<?php defined('BUGGY') or exit; ?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<meta name="theme-color" content="#17110c" media="(prefers-color-scheme: dark)">
<meta name="theme-color" content="#f3e7cb" media="(prefers-color-scheme: light)">
<title>Buggy Trip</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Anton&family=Work+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/app.css?v=<?= filemtime(__DIR__ . "/../assets/app.css") ?>">
</head>
<body class="is-entry">
<?php require __DIR__ . '/dunes.php'; ?>
<main class="entry">
  <div class="entry-card">
    <h1 class="wordmark">Buggy Trip</h1>
    <?php if ($gateError === 'not-configured'): ?>
      <p class="entry-lede">This site isn’t set up yet. Set the <code>SITE_PASSWORD</code> app setting and reload.</p>
    <?php else: ?>
      <p class="entry-lede">Rides, seats and who owes whom, for the group. Enter the group password to continue.</p>
      <form method="post" class="stack" autocomplete="off">
        <label class="field">
          <span>Group password</span>
          <input type="password" name="site_password" required autofocus autocomplete="current-password"
                 <?php if ($gateError): ?>aria-invalid="true" aria-describedby="gateError"<?php endif; ?>>
        </label>
        <?php if ($gateError): ?><p class="form-msg is-error" id="gateError" role="alert"><?= h($gateError) ?></p><?php endif; ?>
        <button type="submit" class="btn btn-primary btn-block">Enter</button>
      </form>
    <?php endif; ?>
  </div>
</main>
</body>
</html>
