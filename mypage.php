<?php
session_start();
require_once 'session_config.php';
require_once 'security_headers.php';
require_once 'funcs.php';

// ログインチェック
loginCheck();

// ユーザー情報の取得
$user = getUserInfo($_SESSION['user_id']);

// データベース接続
$pdo = db_conn();


// ユーザーが選択したフロンティアの進捗状況を取得
function getUserSelectedFrontierProgress($pdo, $user_id) {
    $stmt = $pdo->prepare("
        SELECT f.id, f.name, f.category, f.image_url, ufp.status, ufp.start_time, ufp.completion_time
        FROM gs_chiiki_frontier f
        JOIN user_frontier_progress ufp ON f.id = ufp.frontier_id
        WHERE ufp.user_id = :user_id
        ORDER BY CASE WHEN ufp.status = 'in_progress' THEN 1
                      WHEN ufp.status = 'not_started' THEN 2
                      ELSE 3 END,
                 ufp.start_time DESC
    ");
    $stmt->execute([':user_id' => $user_id]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// ユーザーが申し込んだフロンティアの予約状況を取得
function getUserBookingStatus($pdo, $user_id) {
    $stmt = $pdo->prepare("
        SELECT 
            br.id AS booking_id, 
            br.frontier_id,
            f.name AS frontier_name, 
            f.category,
            f.image_url, 
            br.status AS booking_status,
            br.created_at,
            br.user_message,
            br.admin_reply,
            brs.id AS slot_id,
            brs.date,
            brs.start_time,
            brs.end_time,
            brs.is_confirmed
        FROM booking_requests br
        JOIN gs_chiiki_frontier f ON br.frontier_id = f.id
        LEFT JOIN booking_request_slots brs ON br.id = brs.booking_request_id
        WHERE br.user_id = :user_id
        ORDER BY br.created_at DESC, brs.date ASC, brs.start_time ASC
    ");
    $stmt->execute([':user_id' => $user_id]);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $groupedBookings = [];
    foreach ($results as $row) {
        $bookingId = $row['booking_id'];
        if (!isset($groupedBookings[$bookingId])) {
            $groupedBookings[$bookingId] = [
                'booking_id' => $bookingId,
                'frontier_id' => $row['frontier_id'],
                'frontier_name' => $row['frontier_name'],
                'category' => $row['category'],
                'image_url' => $row['image_url'],
                'booking_status' => $row['booking_status'],
                'created_at' => $row['created_at'],
                'user_message' => $row['user_message'],
                'admin_reply' => $row['admin_reply'],
                'slots' => []
            ];
        }
        if ($row['slot_id'] !== null) {
            $groupedBookings[$bookingId]['slots'][] = [
                'slot_id' => $row['slot_id'],
                'date' => $row['date'],
                'start_time' => $row['start_time'],
                'end_time' => $row['end_time'],
                'is_confirmed' => $row['is_confirmed']
            ];
        }
    }
    return array_values($groupedBookings);
}

// 予約全体のステータスを決定する関数
function determineOverallStatus($booking_slots) {
    $confirmed_count = 0;
    $rejected_count = 0;
    $pending_count = 0;
    $total_count = count($booking_slots);

    foreach ($booking_slots as $slot) {
        if ($slot['is_confirmed'] == 1) {
            $confirmed_count++;
        } elseif ($slot['is_confirmed'] == -1) {
            $rejected_count++;
        } else {
            $pending_count++;
        }
    }

    if ($pending_count > 0) {
        return 'pending';
    } elseif ($confirmed_count > 0) {
        return 'confirmed';
    } elseif ($rejected_count == $total_count) {
        return 'rejected';
    } else {
        return 'unknown';
    }
}

$frontierProgress = getUserSelectedFrontierProgress($pdo, $user['id']);
$bookingStatus = getUserBookingStatus($pdo, $user['id']);

// 進捗状況の計算
$totalFrontiers = count($frontierProgress);
$completedFrontiers = count(array_filter($frontierProgress, function($f) { return $f['status'] == 'completed'; }));
$inProgressFrontiers = count(array_filter($frontierProgress, function($f) { return $f['status'] == 'in_progress'; }));
$overallProgress = $totalFrontiers > 0 ? ($completedFrontiers / $totalFrontiers) * 100 : 0;

// カテゴリごとの進捗を計算
$categoryProgress = [];
foreach ($frontierProgress as $frontier) {
    $category = $frontier['category'];
    if (!isset($categoryProgress[$category])) {
        $categoryProgress[$category] = ['total' => 0, 'completed' => 0];
    }
    $categoryProgress[$category]['total']++;
    if ($frontier['status'] == 'completed') {
        $categoryProgress[$category]['completed']++;
    }
}
?>

<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= h($user['name']) ?>さんのマイページ - ZOUUU</title>
    <link rel="icon" type="image/png" href="./img/favicon.ico">
    <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+JP:wght@400;700;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.1/css/all.min.css">
    <script src="./js/jquery-3.6.0.min.js"></script>
    <style>
        body {
            font-family: 'Noto Sans JP', sans-serif;
            background-color: #f8f9fa;
            color: #333;
            margin: 0;
            padding: 0;
            display: flex;
            flex-direction: column;
            min-height: 100vh;
        }
        .container {
            width: 90%;
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
        }
        header {
            background-color: #fff;
            color: #0c344e;
            padding: 15px 0;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        header .container {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        header .logo {
            font-size: 1.5rem;
            font-weight: bold;
        }
        header nav ul {
            list-style-type: none;
            margin: 0;
            padding: 0;
            display: flex;
        }
        header nav ul li {
            margin-left: 20px;
        }
        header nav ul li a {
            color: #0c344e;
            text-decoration: none;
            font-size: 1rem;
            padding: 5px 10px;
            transition: background-color 0.3s;
        }
        header nav ul li a:hover {
            background-color: #f0f0f0;
            border-radius: 5px;
        }
        main {
            flex: 1;
        }
        .card {
            background-color: #fff;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            padding: 20px;
            margin-bottom: 20px;
        }
        .frontier-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 20px;
        }
        .frontier-item {
            display: flex;
            flex-direction: column;
            border: 1px solid #e0e0e0;
            border-radius: 8px;
            overflow: hidden;
        }
        .frontier-image {
            width: 100%;
            height: 200px;
            object-fit: cover;
        }
        .frontier-content {
            padding: 15px;
            flex-grow: 1;
            display: flex;
            flex-direction: column;
        }
        .frontier-content .btn {
            margin-top: auto;
        }
        .form-group {
            margin-bottom: 15px;
        }
        .form-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: bold;
        }
        .form-control {
            width: 100%;
            padding: 8px;
            border: 1px solid #d9d9d9;
            border-radius: 4px;
            font-size: 16px;
        }
        textarea.form-control {
            min-height: 100px;
        }
        .btn-container {
            text-align: right;
            margin-top: 10px;
        }

        h1, h2, h3, h4 {
            color: #0c344e;
        }
        .progress-bar {
            width: 100%;
            background-color: #e0e0e0;
            border-radius: 5px;
            margin-top: 10px;
        }
        .progress {
            height: 20px;
            background-color: #28a745;
            border-radius: 5px;
            transition: width 0.5s ease-in-out;
        }
        .tag {
            display: inline-block;
            background-color: #e0e0e0;
            padding: 5px 10px;
            border-radius: 3px;
            font-size: 0.9rem;
            margin-right: 5px;
        }
        .btn {
            display: inline-block;
            padding: 10px 15px;
            background-color: #0c344e;
            color: #fff;
            text-decoration: none;
            border-radius: 5px;
            transition: background-color 0.3s, opacity 0.3s;
            font-size: 1rem;
            border: none;
            cursor: pointer;
        }
        .btn:hover {
            background-color: #0a2a3f;
            opacity: 0.9;
        }
        .btn-small {
            padding: 5px 10px;
            font-size: 0.9rem;
        }
        .btn-cancel {
            background-color: #6c757d;
        }
        .btn-secondary {
            background-color: #6c757d;
        }
        footer {
            background-color: #fff;
            color: #0c344e;
            padding: 20px 0;
            width: 100%;
            border-top: 1px solid #e0e0e0;
        }
        footer .container {
            text-align: center;
        }
        footer p {
            margin: 0;
        }
        .footer-logo {
            font-size: 1.5em;
            font-weight: bold;
        }
        .frontier-item {
            display: flex;
            align-items: flex-start;
            border-bottom: 1px solid #e0e0e0;
            padding-bottom: 20px;
            margin-bottom: 20px;
        }
        .frontier-item:last-child {
            border-bottom: none;
            margin-bottom: 0;
        }
        .frontier-image {
            width: 150px;
            height: 150px;
            object-fit: cover;
            border-radius: 8px;
            margin-right: 20px;
        }
        .frontier-content {
            flex: 1;
        }
        .modal {
            display: none;
            position: fixed;
            z-index: 1;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            overflow: auto;
            background-color: rgba(0,0,0,0.4);
        }
        .modal-content {
            background-color: #fefefe;
            margin: 15% auto;
            padding: 20px;
            border: 1px solid #888;
            width: 80%;
            max-width: 500px;
            border-radius: 8px;
        }
        .hidden-content {
            margin-top: 20px;
        }
        .frontier-item ul {
            list-style-type: none;
            padding-left: 0;
        }
        .frontier-item li {
            margin-bottom: 10px;
        }
        .status {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 3px;
            font-size: 0.9em;
            margin-left: 5px;
            line-height: 1.2;
        }
        .status.pending {
            background-color: #ffc107;
            color: #000;
        }
        .status.confirmed {
            background-color: #28a745;
            color: #fff;
        }
        .status.rejected {
            background-color: #dc3545;
            color: #fff;
        }

        /* スロットステータスのスタイルも統一 */
        .slot-status {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 3px;
            font-size: 0.9em;
            margin-left: 5px;
            line-height: 1.2;
        }
        .slot-pending {
            background-color: #ffc107;
            color: #000;
        }
        .slot-confirmed {
            background-color: #28a745;
            color: #fff;
        }
        .slot-rejected {
            background-color: #dc3545;
            color: #fff;
        }

        #notification-list {
            display: flex;
            flex-wrap: wrap;
            gap: 20px; /* カード間のスペース */
        }

        #hidden-notifications {
            display: flex;
            flex-wrap: wrap;
            gap: 20px; /* カード間のスペース */
            margin-top: 20px;
        }

        .notification-card {
            flex: 1;
            min-width: 250px; /* カードの最小幅 */
            max-width: 30%; /* カードの最大幅 */
            background-color: #fff;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            padding: 20px;
            margin-bottom: 20px;
        }

        .btn-container {
            text-align: right; /* もっと見るボタンを右寄せ */
        }

    </style>
</head>
<body>
    <header>
        <div class="container">
            <div class="logo">ZOUUU </div>
            <nav>
                <ul>
                    <li><a href="chiiki_kasseika.php"><b>フロンティア一覧</b></a></li>
                    <li><a href="mypage.php"><b>マイページ</b></a></li>
                    <li><a href="logoutmypage.php"><b>ログアウト</b></a></li>
                </ul>
            </nav>
        </div>
    </header>

    <main class="container">
     <!-- お知らせセクション -->
     <section class="card mb-4">
            <h2>お知らせ</h2>
            <div class="card-body">
                <div id="notification-list">
                    <?php
                    // ユーザーのIDを取得
                    $user_id = $_SESSION['user_id'];

                    // 最新のお知らせを取得
                    try {
                        $stmt = $pdo->prepare("
                            SELECT n.*, u.name AS sender_name
                            FROM notifications n
                            JOIN notification_recipients nr ON n.id = nr.notification_id
                            JOIN user_table u ON n.sender_id = u.id
                            WHERE nr.user_id = :user_id
                            ORDER BY n.created_at DESC
                            LIMIT 3
                        ");
                        $stmt->execute([':user_id' => $user_id]);
                        $latest_notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    } catch (PDOException $e) {
                        die("データ取得エラー: " . $e->getMessage());
                    }

                    // 最新のお知らせをカード形式で表示
                    foreach ($latest_notifications as $notification): ?>
                        <div class="notification-card card">
                            <div class="card-body">
                                <h5 class="card-title"><?= htmlspecialchars($notification['sender_name']) ?></h5>
                                <p class="card-text"><?= htmlspecialchars($notification['message']) ?></p>
                                <p class="card-text text-muted"><small><?= htmlspecialchars($notification['created_at']) ?></small></p>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div class="btn-container">
                    <button id="show-more-notifications" class="btn btn-secondary mt-2">もっと見る</button>
                </div>

                <div id="hidden-notifications" style="display:none;">
                    <?php
                    // 残りのお知らせを取得
                    try {
                        $stmt = $pdo->prepare("
                            SELECT n.*, u.name AS sender_name
                            FROM notifications n
                            JOIN notification_recipients nr ON n.id = nr.notification_id
                            JOIN user_table u ON n.sender_id = u.id
                            WHERE nr.user_id = :user_id
                            ORDER BY n.created_at DESC
                            LIMIT 3 OFFSET 3
                        ");
                        $stmt->execute([':user_id' => $user_id]);
                        $more_notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    } catch (PDOException $e) {
                        die("データ取得エラー: " . $e->getMessage());
                    }

                    // 残りのお知らせをカード形式で表示
                    foreach ($more_notifications as $notification): ?>
                        <div class="notification-card card">
                            <div class="card-body">
                                <h5 class="card-title"><?= htmlspecialchars($notification['sender_name']) ?></h5>
                                <p class="card-text"><?= htmlspecialchars($notification['message']) ?></p>
                                <p class="card-text text-muted"><small><?= htmlspecialchars($notification['created_at']) ?></small></p>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>

    <!-- テーマ設定セクション -->
    <section class="card">
        <h2>テーマ設定</h2>
        <form id="themeForm">
            <div class="form-group">
                <label for="themeCategory">カテゴリを選択してください:</label>
                <select id="themeCategory" name="themeCategory" class="form-control">
                    <option value="防災・防犯対策" <?= $user['theme'] === '防災・防犯対策' ? 'selected' : '' ?>>防災・防犯対策</option>
                    <option value="子育て支援" <?= $user['theme'] === '子育て支援' ? 'selected' : '' ?>>子育て支援</option>
                    <option value="福祉・保健衛生" <?= $user['theme'] === '福祉・保健衛生' ? 'selected' : '' ?>>福祉・保健衛生</option>
                    <option value="環境" <?= $user['theme'] === '環境' ? 'selected' : '' ?>>環境</option>
                    <option value="地域活性化" <?= $user['theme'] === '地域活性化' ? 'selected' : '' ?>>地域活性化</option>
                    <option value="人口対策" <?= $user['theme'] === '人口対策' ? 'selected' : '' ?>>人口対策</option>
                    <option value="文化振興" <?= $user['theme'] === '文化振興' ? 'selected' : '' ?>>文化振興</option>
                    <option value="都市基盤整備" <?= $user['theme'] === '都市基盤整備' ? 'selected' : '' ?>>都市基盤整備</option>
                    <option value="教育" <?= $user['theme'] === '教育' ? 'selected' : '' ?>>教育</option>
                </select>
            </div>
            <div class="btn-container">
                <button type="submit" class="btn">保存</button>
            </div>
        </form>
        <p>現在のテーマ: <span id="currentTheme"><?= h($user['theme']) ?></span></p>
    </section>

    <!-- 学習テーマ進捗状況セクション
    <?php if ($totalFrontiers > 0): ?>
        <section class="card">
            <h2>学習テーマ進捗状況</h2>
            <?php foreach ($categoryProgress as $category => $progress): ?>
                <h3><?= h($category) ?></h3>
                <p>進捗率: <?= number_format(($progress['completed'] / $progress['total']) * 100, 1) ?>%</p>
                <div class="progress-bar">
                    <div class="progress" style="width: <?= ($progress['completed'] / $progress['total']) * 100 ?>%;"></div>
                </div>
                <p>完了: <?= $progress['completed'] ?> / <?= $progress['total'] ?></p>
            <?php endforeach; ?>
        </section> -->

        <section class="card">
            <h2>「学習する」を選択したフロンティア</h2>
            <div class="frontier-grid">
                <?php
                $frontierCount = count($frontierProgress);
                foreach (array_slice($frontierProgress, 0, 3) as $index => $frontier):
                ?>
                    <div class="frontier-item">
                        <?php if (!empty($frontier['image_url'])): ?>
                            <img src="<?= h($frontier['image_url']) ?>" alt="<?= h($frontier['name']) ?>" class="frontier-image">
                        <?php endif; ?>
                        <div class="frontier-content">
                            <h3><?= h($frontier['name']) ?></h3>
                            <p>カテゴリー: <span class="tag"><?= h($frontier['category']) ?></span></p>
                            <p>状態: 
                                <?php
                                switch($frontier['status']) {
                                    case 'not_started':
                                        echo '<i class="fas fa-circle" style="color: #ccc;"></i> 未開始';
                                        break;
                                    case 'in_progress':
                                        echo '<i class="fas fa-spinner fa-spin" style="color: #ffc107;"></i> 進行中';
                                        break;
                                    case 'paused':
                                        echo '<i class="fas fa-pause-circle" style="color: #ffc107;"></i> 一時停止';
                                        break;
                                    case 'completed':
                                        echo '<i class="fas fa-check-circle" style="color: #28a745;"></i> 完了';
                                        break;
                                }
                                ?>
                            </p>
                            <?php if ($frontier['start_time']): ?>
                                <p><i class="fas fa-play"></i> 開始日時: <?= h($frontier['start_time']) ?></p>
                            <?php endif; ?>
                            <?php if ($frontier['completion_time']): ?>
                                <p><i class="fas fa-flag-checkered"></i> 完了日時: <?= h($frontier['completion_time']) ?></p>
                            <?php endif; ?>
                            <a href="user_learning.php?frontier_id=<?= h($frontier['id']) ?>" class="btn">
                                <?php
                                if ($frontier['status'] == 'completed') {
                                    echo '復習する';
                                } elseif ($frontier['status'] == 'paused') {
                                    echo '再開する';
                                } else {
                                    echo '学習する';
                                }
                                ?>
                            </a>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            
            <?php if ($frontierCount > 3): ?>
                <div class="btn-container">
                    <button id="showMoreLearning" class="btn btn-secondary">もっと見る (<?= $frontierCount - 3 ?>)</button>
                </div>
                <div id="hiddenLearning" class="hidden-content" style="display:none;">
                    <div class="frontier-grid">
                        <?php foreach (array_slice($frontierProgress, 3) as $frontier): ?>
                            <div class="frontier-item">
                                <?php if (!empty($frontier['image_url'])): ?>
                                    <img src="<?= h($frontier['image_url']) ?>" alt="<?= h($frontier['name']) ?>" class="frontier-image">
                                <?php endif; ?>
                                <div class="frontier-content">
                                    <h3><?= h($frontier['name']) ?></h3>
                                    <p>カテゴリー: <span class="tag"><?= h($frontier['category']) ?></span></p>
                                    <p>状態: 
                                        <?php
                                        switch($frontier['status']) {
                                            case 'not_started':
                                                echo '<i class="fas fa-circle" style="color: #ccc;"></i> 未開始';
                                                break;
                                            case 'in_progress':
                                                echo '<i class="fas fa-spinner fa-spin" style="color: #ffc107;"></i> 進行中';
                                                break;
                                            case 'paused':
                                                echo '<i class="fas fa-pause-circle" style="color: #ffc107;"></i> 一時停止';
                                                break;
                                            case 'completed':
                                                echo '<i class="fas fa-check-circle" style="color: #28a745;"></i> 完了';
                                                break;
                                        }
                                        ?>
                                    </p>
                                    <?php if ($frontier['start_time']): ?>
                                        <p><i class="fas fa-play"></i> 開始日時: <?= h($frontier['start_time']) ?></p>
                                    <?php endif; ?>
                                    <?php if ($frontier['completion_time']): ?>
                                        <p><i class="fas fa-flag-checkered"></i> 完了日時: <?= h($frontier['completion_time']) ?></p>
                                    <?php endif; ?>
                                    <a href="user_learning.php?frontier_id=<?= h($frontier['id']) ?>" class="btn">
                                        <?php
                                        if ($frontier['status'] == 'completed') {
                                            echo '復習する';
                                        } elseif ($frontier['status'] == 'paused') {
                                            echo '再開する';
                                        } else {
                                            echo '学習する';
                                        }
                                        ?>
                                    </a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
        </section>

    <?php else: ?>
        <section class="card">
            <h2>選択したフロンティア</h2>
            <p>まだフロンティアを選択していません。<a href="chiiki_kasseika.php">フロンティア一覧</a>から興味のあるものを選んでみましょう。</p>
        </section>
    <?php endif; ?>

    <section class="card">
        <h2>課題（探究内容）設定</h2>
        <form id="taskForm">
            <div class="form-group">
                <textarea id="taskContent" name="taskContent" class="form-control" rows="4"><?= h($user['inquiry_content']) ?></textarea>
            </div>
            <div class="btn-container">
                <button type="submit" class="btn">保存</button>
            </div>
        </form>
    </section>

    <section class="card">
        <h2>解決のための仮説設定</h2>
        <form id="hypothesisForm">
            <div class="form-group">
                <textarea id="hypothesisContent" name="hypothesisContent" class="form-control" rows="4"><?= h($user['hypothesis']) ?></textarea>
            </div>
            <div class="btn-container">
                <button type="submit" class="btn">保存</button>
            </div>
        </form>
    </section>

    <section class="card">
        <h2>「体験申込」を選択したフロンティア</h2>
        <div class="frontier-grid">
            <?php
            $bookingCount = count($bookingStatus);
            foreach (array_slice($bookingStatus, 0, 3) as $booking):
                $overall_status = determineOverallStatus($booking['slots']);
            ?>
                <div class="frontier-item">
                    <?php if (!empty($booking['image_url'])): ?>
                        <img src="<?= h($booking['image_url']) ?>" alt="<?= h($booking['frontier_name']) ?>" class="frontier-image">
                    <?php endif; ?>
                    <div class="frontier-content">
                        <h3><?= h($booking['frontier_name']) ?></h3>
                        <p>カテゴリー: <span class="tag"><?= h($booking['category']) ?></span></p>
                        <p><i class="fas fa-calendar-alt"></i> 申込日時: <?= h($booking['created_at']) ?></p>
                        <p>ステータス: <span class="status <?= h($overall_status) ?>">
                            <?php
                            switch($overall_status) {
                                case 'confirmed':
                                    echo '確定';
                                    break;
                                case 'rejected':
                                    echo 'NG';
                                    break;
                                case 'pending':
                                    echo '承認待ち';
                                    break;
                                default:
                                    echo '不明';
                                    break;
                            }
                            ?>
                        </span></p>
                        
                        <?php if (!empty($booking['user_message'])): ?>
                            <div class="user-message">
                                <h4>あなたのメッセージ:</h4>
                                <p><?= nl2br(h($booking['user_message'])) ?></p>
                            </div>
                        <?php endif; ?>
                        
                        <?php if (!empty($booking['admin_reply'])): ?>
                            <div class="admin-reply">
                                <h4>管理者からの返信:</h4>
                                <p><?= nl2br(h($booking['admin_reply'])) ?></p>
                            </div>
                        <?php endif; ?>
                        
                        <a href="view_booking_details.php?booking_id=<?= h($booking['booking_id']) ?>" class="btn">詳細を見る</a>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <?php if ($bookingCount > 3): ?>
            <div class="btn-container">
                <button id="showMoreBooking" class="btn btn-secondary">もっと見る (<?= $bookingCount - 3 ?>)</button>
            </div>
            <div id="hiddenBooking" class="hidden-content" style="display:none;">
                <div class="frontier-grid">
                    <?php foreach (array_slice($bookingStatus, 3) as $booking):
                        $overall_status = determineOverallStatus($booking['slots']);
                    ?>
                        <div class="frontier-item">
                            <?php if (!empty($booking['image_url'])): ?>
                                <img src="<?= h($booking['image_url']) ?>" alt="<?= h($booking['frontier_name']) ?>" class="frontier-image">
                            <?php endif; ?>
                            <div class="frontier-content">
                                <h3><?= h($booking['frontier_name']) ?></h3>
                                <p>カテゴリー: <span class="tag"><?= h($booking['category']) ?></span></p>
                                <p><i class="fas fa-calendar-alt"></i> 申込日時: <?= h($booking['created_at']) ?></p>
                                <p>
                                    ステータス: 
                                    <span class="status <?= h($overall_status) ?>">
                                        <?php
                                        switch($overall_status) {
                                            case 'confirmed':
                                                echo '確定';
                                                break;
                                            case 'rejected':
                                                echo 'NG';
                                                break;
                                            case 'pending':
                                                echo '承認待ち';
                                                break;
                                            default:
                                                echo '不明';
                                                break;
                                        }
                                        ?>
                                    </span>
                                </p>
                                <?php if (!empty($booking['slots'])): ?>
                                    <p><b>体験希望日時:</b></p>
                                    <ul>
                                    <?php foreach ($booking['slots'] as $slot): ?>
                                        <li>
                                            <?= h($slot['date'] . ' ' . $slot['start_time'] . ' - ' . $slot['end_time']) ?>
                                            <?php
                                            if ($slot['is_confirmed'] == 1) {
                                                echo '<span class="slot-status slot-confirmed">確定</span>';
                                            } elseif ($slot['is_confirmed'] == -1) {
                                                echo '<span class="slot-status slot-rejected">NG</span>';
                                            } else {
                                                echo '<span class="slot-status slot-pending">承認待ち</span>';
                                            }
                                            ?>
                                        </li>
                                    <?php endforeach; ?>
                                    </ul>
                                <?php else: ?>
                                    <p>予約スロットはありません。</p>
                                <?php endif; ?>
                                <a href="view_booking_details.php?booking_id=<?= h($booking['booking_id']) ?>" class="btn">詳細を見る</a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>
    </section>

    <section class="card">
        <h2>学びレポート</h2>
        <form id="learningReportForm">
            <div class="form-group">
                <textarea id="learningReportContent" name="learningReportContent" class="form-control" rows="4"><?= h($user['learning_report']) ?></textarea>
            </div>
            <div class="btn-container">
                <button type="submit" class="btn">保存</button>
            </div>
        </form>
    </section>

    <section class="card">
        <h2>要因分析</h2>
        <form id="factorAnalysisForm">
            <div class="form-group">
                <textarea id="factorAnalysisContent" name="factorAnalysisContent" class="form-control" rows="4"><?= h($user['factor_analysis']) ?></textarea>
            </div>
            <div class="btn-container">
                <button type="submit" class="btn">保存</button>
            </div>
        </form>
    </section>

    <section class="card">
        <h2>まとめ</h2>
        <form id="summaryForm">
            <div class="form-group">
                <textarea id="summaryContent" name="summaryContent" class="form-control" rows="4"><?= h($user['summary']) ?></textarea>
            </div>
            <div class="btn-container">
                <button type="submit" class="btn">保存</button>
            </div>
        </form>
    </section>

    <section class="card">      
        <h2>発表資料</h2>
        <form id="presentationForm">
            <div class="form-group">
                <label for="presentationUrl">Googleのスプレッドシート、プレゼンテーション、ドキュメントのURL:</label>
                <input type="url" id="presentationUrl" name="presentationUrl" class="form-control" value="<?= h($user['presentation_url']) ?>">
            </div>
            <div class="btn-container">
                <button type="submit" class="btn">保存</button>
            </div>
        </form>
    </section>

     <!-- ユーザー情報セクション -->
     <section class="card">
        <h2>ユーザー情報 <button id="editUserInfo" class="btn btn-small">編集</button></h2>
        <p><i class="fas fa-user"></i> 名前: <span id="userName"><?= h($user['name']) ?></span></p>
        <p><i class="fas fa-envelope"></i> メールアドレス: <span id="userEmail"><?= h($user['email']) ?></span></p>
        <p><i class="fas fa-calendar-alt"></i> 登録日: <?= h($user['created_at']) ?></p>
        <p><i class="fas fa-clock"></i> 最終ログイン: <?= h($user['last_login']) ?></p>
    </section>

</main>

    <footer>
        <div class="container">
            <p class="footer-logo">ZOUUU</p>
            <small>&copy; 2024 ZOUUU. All rights reserved.</small>
        </div>
    </footer>

    <!-- モーダルウィンドウ -->
    <div id="userInfoModal" class="modal">
        <div class="modal-content">
            <h2>ユーザー情報編集</h2>
            <form id="userInfoForm">
                <label for="editName">名前:</label>
                <input type="text" id="editName" name="name" value="<?= h($user['name']) ?>" required>

                <label for="editEmail">メールアドレス:</label>
                <input type="email" id="editEmail" name="email" required>

                <button type="submit" class="btn">保存</button>
                <button type="button" class="btn btn-cancel" id="closeModal">キャンセル</button>
            </form>
        </div>
    </div>

    <script>
    $(document).ready(function() {
        // 進捗バーのアニメーション
        $('.progress').each(function() {
            var $bar = $(this);
            var width = $bar.width();
            $bar.width(0).animate({width: width}, 1000);
        });

        // フロンティアの表示/非表示を切り替える機能
        $('.frontier-item h3').click(function() {
            $(this).siblings('p, a').slideToggle();
        });

        // ボタンのホバーエフェクト
        $('.btn').hover(
            function() { 
                $(this).css('opacity', '0.8'); 
            },
            function() { 
                $(this).css('opacity', '1'); 
            }
        );

        // ユーザー情報編集
        $('#editUserInfo').click(function() {
            $('#editEmail').val($('#userEmail').text());
            $('#userInfoModal').show();
        });

        $('#closeModal').click(function() {
            $('#userInfoModal').hide();
        });

        $('#userInfoForm').submit(function(e) {
            e.preventDefault();
            var newName = $('#editName').val();
            var newEmail = $('#editEmail').val();
            console.log("Sending data:", { name: newName, email: newEmail });  // 追加
            $.ajax({
                url: 'update_user_info.php',
                method: 'POST',
                data: { name: newName, email: newEmail },
                success: function(response) {
                    console.log("Server response:", response);  // 追加
                    if (response.success) {
                        $('#userName').text(newName);
                        $('#userEmail').text(newEmail);
                        $('#userInfoModal').hide();
                        alert('ユーザー情報が更新されました。');
                    } else {
                        alert('更新に失敗しました: ' + response.message);
                    }
                },
                error: function(xhr, status, error) {
                    console.error("AJAX Error:", status, error);  // 追加
                    alert('サーバーとの通信に失敗しました。');
                }
            });
        });

        $('#themeForm').submit(function(e) {
            e.preventDefault();
            var newTheme = $('#themeCategory').val();  // テーマの取得
            console.log("Theme:", newTheme);  // デバッグ用ログ
            if (!newTheme) {
                alert('テーマを選択してください。');
                return;
            }
            $.ajax({
                url: 'update_user_info.php',
                method: 'POST',
                data: { themeCategory: newTheme },
                success: function(response) {
                    console.log("Server response:", response);  // デバッグ用ログ
                    if (response.success) {
                        $('#currentTheme').text(newTheme);  // 現在のテーマを更新
                        $('#themeCategory').val(newTheme);  // 選択したカテゴリを保持
                        alert('テーマが正常に更新されました。');
                    } else {
                        alert('更新に失敗しました: ' + response.message);
                    }
                },
                error: function(xhr, status, error) {
                    console.error("AJAX Error:", status, error);  // デバッグ用ログ
                    alert('サーバーとの通信に失敗しました。');
                }
            });
        });

        // 「もっと見る」ボタンの機能（フロンティア）
        $('#showMoreLearning').click(function() {
            var $button = $(this);
            $('#hiddenLearning').slideToggle('fast', function() {
                var currentText = $button.text();
                console.log("Current text:", currentText);  // デバッグ用
                $button.text(currentText === "もっと見る (<?= $frontierCount - 3 ?>)" ? "閉じる" : "もっと見る (<?= $frontierCount - 3 ?>)");
                console.log("New text:", $button.text());  // デバッグ用
            });
        });

        // 「もっと見る」ボタンの機能（体験申込）
        $('#showMoreBooking').click(function() {
            var $button = $(this);
            $('#hiddenBooking').slideToggle('fast', function() {
                var currentText = $button.text();
                console.log("Current text:", currentText);  // デバッグ用
                $button.text(currentText === "もっと見る (<?= $bookingCount - 3 ?>)" ? "閉じる" : "もっと見る (<?= $bookingCount - 3 ?>)");
                console.log("New text:", $button.text());  // デバッグ用
            });
        });
    });

   
    function saveContent(data) {
    $.ajax({
        url: 'update_learning_factor_summary.php',
        method: 'POST',
        data: data,
        dataType: 'json',  // JSONレスポンスを期待することを明示
        success: function(response) {
            if (response.success) {
                alert('データが保存されました。');
            } else {
                alert('保存に失敗しました: ' + response.message);
            }
        },
        error: function(xhr, status, error) {
            console.error("AJAX Error:", status, error);  // エラーログを追加
            alert('サーバーとの通信に失敗しました。');
        }
    });
}

$('#taskForm').submit(function(e) {
    e.preventDefault();
    var taskContent = $('#taskContent').val();
    saveContent({ taskContent: taskContent });
});

$('#hypothesisForm').submit(function(e) {
    e.preventDefault();
    var hypothesisContent = $('#hypothesisContent').val();
    saveContent({ hypothesisContent: hypothesisContent });
});

$('#learningReportForm').submit(function(e) {
    e.preventDefault();
    var learningReportContent = $('#learningReportContent').val();
    saveContent({ learningReportContent: learningReportContent });
});

$('#factorAnalysisForm').submit(function(e) {
    e.preventDefault();
    var factorAnalysisContent = $('#factorAnalysisContent').val();
    saveContent({ factorAnalysisContent: factorAnalysisContent });
});

$('#summaryForm').submit(function(e) {
    e.preventDefault();
    var summaryContent = $('#summaryContent').val();
    saveContent({ summaryContent: summaryContent });
});

// presentationFormは別の処理を使用しているため、そのまま残します
$('#presentationForm').submit(function(e) {
    e.preventDefault();
    var presentationUrl = $('#presentationUrl').val();
    $.ajax({
        url: 'upload_presentation.php',
        method: 'POST',
        data: { presentationUrl: presentationUrl },
        dataType: 'json',  // JSONレスポンスを期待することを明示
        success: function(response) {
            if (response.success) {
                alert('発表資料のURLが保存されました。');
            } else {
                alert('保存に失敗しました: ' + response.message);
            }
        },
        error: function(xhr, status, error) {
            console.error("AJAX Error:", status, error);  // エラーログを追加
            alert('サーバーとの通信に失敗しました。');
        }
    });
});
            

document.getElementById('show-more-notifications').addEventListener('click', function() {
    const hiddenNotifications = document.getElementById('hidden-notifications');
    hiddenNotifications.style.display = hiddenNotifications.style.display === 'none' ? 'flex' : 'none';
    this.textContent = hiddenNotifications.style.display === 'flex' ? '閉じる' : 'もっと見る';
});

            </script>

</body>
</html>