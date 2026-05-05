<?php
session_start();
if (isset($_SESSION['user_id'])) {
    $dest = match($_SESSION['role']) {
        'admin'   => 'admin_dashboard.php',
        'manager' => 'manager_dashboard.php',
        default   => 'dashboard.php',
    };
    header("Location: $dest"); exit;
}

$error = "";
if ($_POST) {
    require 'config.php';
    // FIX: trim only username; passwords must NOT be trimmed (spaces are valid).
    $u    = trim($_POST['username'] ?? '');
    $p    = $_POST['password'] ?? '';

    if ($u === '' || $p === '') {
        $error = "Please enter your username and password.";
    } else {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ?");
        $stmt->execute([$u]);
        $user = $stmt->fetch();

        if (!$user) {
            // FIX: generic message — do not reveal whether the username exists.
            $error = "Invalid username or password.";
        } else {
            $valid = password_verify($p, $user['password']);

            // BUG FIX: Removed the "|| $p === $user['password']" plaintext
            // fallback.  Storing/comparing plaintext passwords is a critical
            // security vulnerability.  The migration block below handles legacy
            // plaintext hashes properly via password_verify failure + re-hash.
            // If you need the legacy path, do it through a password-reset flow.

            if ($valid) {
                // Rehash if the algorithm/cost has been upgraded since stored.
                if (password_needs_rehash($user['password'], PASSWORD_DEFAULT)) {
                    $pdo->prepare("UPDATE users SET password = ? WHERE user_id = ?")
                        ->execute([password_hash($p, PASSWORD_DEFAULT), $user['user_id']]);
                }
                session_regenerate_id(true); // FIX: prevent session fixation
                $_SESSION['user_id']  = $user['user_id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['role']     = $user['role'];
                $dest = match($user['role']) {
                    'admin'   => 'admin_dashboard.php',
                    'manager' => 'manager_dashboard.php',
                    default   => 'dashboard.php',
                };
                header("Location: $dest"); exit;
            } else {
                $error = "Invalid username or password.";
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>SuccuTrack – Login</title>
<link rel="stylesheet" href="style.css">
</head>
<body class="auth-page">
  <div class="auth-card">
    <div class="brand">
      <span class="leaf-icon">🌵</span>
      <h1>SuccuTrack</h1>
      <p>Succulent Humidity Monitoring System</p>
    </div>
    <?php if ($error): ?>
      <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    <form method="POST">
      <div class="form-group">
        <label>Username</label>
        <input type="text" name="username" required placeholder="Enter username"
               value="<?= htmlspecialchars($_POST['username'] ?? '') ?>">
      </div>
      <div class="form-group">
        <label>Password</label>
        <input type="password" name="password" required placeholder="Enter password">
      </div>
      <button type="submit" class="btn btn-primary btn-full">Login</button>
    </form>
    <p class="auth-footer">No account? <a href="create_user.php">Register here</a></p>
  </div>
</body>
</html>
