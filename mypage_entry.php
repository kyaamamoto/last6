<?php
require_once 'session_config.php';
require_once 'security_headers.php';
require_once 'funcs.php';

$csrf_token = generateToken();
?>
<!DOCTYPE html>
<html lang="ja">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>ユーザー登録</title>
  <link rel="icon" type="image/png" href="./img/favicon.ico">
  <link rel="stylesheet" href="./css/style4.css">
  <link rel="stylesheet" href="./css/education.css">
  <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+JP:wght@400;700;900&display=swap" rel="stylesheet">
  <style>
    .message { color: green; font-weight: bold; margin-top: 10px; }
    .error { color: red; font-weight: bold; margin-top: 10px; }
  </style>
</head>
<body>
<header>
        <div class="container">
            <nav>
                <div class="logo">
                  <a href="education.html">ZOUUU</a>
                </div>
                <ul>
                    <li><a href="education.html#about">初めての方へ</a></li>
                    <li><a href="education.html#contact">お問い合わせ</a></li>
                    <li><a href="login_holder.php">ログイン</a></li>
                    <li><a href="mypage_entry.php" class="btn-register">会員登録</a></li>
                </ul>
            </nav>
        </div>
    </header>

  <div class="login-container">
    <h1>ユーザー登録</h1>
    <?php
    if (isset($_SESSION['registration_error'])) {
        echo "<p class='error' role='alert'>" . h($_SESSION['registration_error']) . "</p>";
        unset($_SESSION['registration_error']);
    }
    ?>
    <form method="post" action="holder_insert.php" onsubmit="return validateForm()">
      <div class="form-group">
        <label for="name">名前:</label>
        <input type="text" name="name" id="name" required aria-required="true">
      </div>
      <div class="form-group">
        <label for="email">メールアドレス (ログインID):</label>
        <input type="email" name="email" id="email" required aria-required="true">
      </div>
      <div class="form-group">
        <label for="lpw">パスワード:</label>
        <input type="password" name="lpw" id="lpw" required aria-required="true" 
               pattern="(?=.*\d)(?=.*[a-z])(?=.*[A-Z]).{8,}" 
               title="少なくとも8文字で、1つの数字、1つの小文字、1つの大文字を含む必要があります">
      </div>
      <div class="form-group">
        <label for="lpw_confirm">パスワード（確認）:</label>
        <input type="password" name="lpw_confirm" id="lpw_confirm" required aria-required="true">
      </div>
      <input type="hidden" name="csrf_token" value="<?php echo h($csrf_token); ?>">
      <button type="submit" class="btn">登録</button>
    </form>
    <div class="btn-register mt-3">
      <a href="login_holder.php">ログインはこちら</a>
    </div>
  </div>

  <script>
  function validateForm() {
      var name = document.forms[0]["name"].value;
      var email = document.forms[0]["email"].value;
      var lpw = document.forms[0]["lpw"].value;
      var lpw_confirm = document.forms[0]["lpw_confirm"].value;

      if (name == "" || email == "" || lpw == "" || lpw_confirm == "") {
          alert("全ての項目を入力してください");
          return false;
      }

      var emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
      if (!emailRegex.test(email)) {
          alert("有効なメールアドレスを入力してください");
          return false;
      }

      var pwRegex = /(?=.*\d)(?=.*[a-z])(?=.*[A-Z]).{8,}/;
      if (!pwRegex.test(lpw)) {
          alert("パスワードは少なくとも8文字で、1つの数字、1つの小文字、1つの大文字を含む必要があります");
          return false;
      }

      if (lpw !== lpw_confirm) {
          alert("パスワードが一致しません");
          return false;
      }

      return true;
  }
  </script>
</body>
</html>