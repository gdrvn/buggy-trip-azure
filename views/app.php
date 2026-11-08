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
<body class="is-loading">

<!-- ============ Sign in / create account ============ -->
<section id="authView" class="auth" hidden>
  <?php require __DIR__ . '/dunes.php'; ?>
  <main class="entry">
    <div class="entry-card">
      <h1 class="wordmark">Buggy Trip</h1>
      <div class="segmented" role="tablist" aria-label="Account">
        <button type="button" role="tab" id="tabLogin" aria-selected="true" data-auth-mode="login">Sign in</button>
        <button type="button" role="tab" id="tabRegister" aria-selected="false" data-auth-mode="register">Create account</button>
      </div>
      <form id="authForm" class="stack" novalidate>
        <label class="field" data-only="register">
          <span>Your name <small>as the group knows you</small></span>
          <input name="name" type="text" maxlength="40" autocomplete="nickname">
        </label>
        <label class="field">
          <span>Username</span>
          <input name="username" type="text" maxlength="32" autocomplete="username" autocapitalize="none" spellcheck="false" required>
        </label>
        <label class="field">
          <span>Password <small data-only="register">at least 8 characters</small></span>
          <input name="password" type="password" autocomplete="current-password" required>
        </label>
        <p class="form-msg" id="authMsg" role="alert"></p>
        <button type="submit" class="btn btn-primary btn-block" id="authSubmit">Sign in</button>
      </form>
    </div>
  </main>
</section>

<!-- ============ App ============ -->
<div id="appView" class="app" hidden>
  <header class="topbar">
    <span class="wordmark wordmark-sm">Buggy Trip</span>
    <nav class="tabs" aria-label="Sections">
      <button type="button" class="tab" data-tab="rides" aria-current="page">
        <svg class="icon" viewBox="0 0 24 24"><use href="#i-car"/></svg>Rides</button>
      <button type="button" class="tab" data-tab="money">
        <svg class="icon" viewBox="0 0 24 24"><use href="#i-coins"/></svg>Money</button>
    </nav>
    <div class="who">
      <span class="avatar" id="meAvatar"></span>
      <span class="who-name" id="meName"></span>
      <button type="button" class="icon-btn" id="logoutBtn" title="Sign out" aria-label="Sign out">
        <svg class="icon" viewBox="0 0 24 24"><use href="#i-logout"/></svg></button>
    </div>
  </header>

  <!-- ---------- Rides ---------- -->
  <main id="ridesView" class="view">
    <div class="datebar">
      <button type="button" class="icon-btn" id="prevDays" aria-label="Earlier days"><svg class="icon" viewBox="0 0 24 24"><use href="#i-left"/></svg></button>
      <ol class="datestrip" id="dateStrip" aria-label="Ride days"></ol>
      <button type="button" class="icon-btn" id="nextDays" aria-label="Later days"><svg class="icon" viewBox="0 0 24 24"><use href="#i-right"/></svg></button>
      <label class="icon-btn date-pick" title="Pick a date">
        <svg class="icon" viewBox="0 0 24 24"><use href="#i-calendar"/></svg>
        <input type="date" id="datePicker" aria-label="Pick a date">
      </label>
    </div>

    <div class="day">
      <div class="day-head">
        <h1 class="day-title" id="dayTitle"></h1>
        <p class="day-sub" id="daySub"></p>
      </div>

      <div class="day-grid">
        <section class="stage" aria-labelledby="fleetHeading">
          <div class="stage-head">
            <h2 id="fleetHeading" class="h-label">Fleet</h2>
            <div class="stage-actions" id="stageActions"></div>
          </div>
          <p class="fleet-hint" id="fleetHint" hidden></p>
          <div class="fleet" id="fleet"></div>
          <div class="overflow-note" id="overflowNote" hidden></div>
        </section>

        <aside class="side">
          <section class="cost" aria-label="Cost">
            <p class="cost-line"><span class="cost-amount" id="perPerson">—</span><span class="cost-unit">per person</span></p>
            <p class="cost-meta" id="costMeta"></p>
          </section>

          <div class="actionbar" id="actionBar">
            <form class="joinsheet" id="joinSheet" hidden>
              <div class="joinsheet-row">
                <label class="field">
                  <span id="joinSheetLabel">Pickup location</span>
                  <input id="pickupInput" type="text" maxlength="80" placeholder="Pousada, street, or meeting point" required>
                </label>
                <label class="field" id="guestField" hidden>
                  <span>Guest’s name <small>their share is charged to you</small></span>
                  <input id="guestInput" type="text" maxlength="40">
                </label>
              </div>
              <div class="joinsheet-actions">
                <button type="button" class="btn btn-ghost" id="joinCancel">Cancel</button>
                <button type="submit" class="btn btn-primary" id="joinConfirm">Join</button>
              </div>
            </form>
            <div class="actionbar-main" id="actionMain"></div>
            <p class="form-msg" id="dayMsg" role="status" aria-live="polite"></p>
          </div>

          <section class="riders" aria-labelledby="ridersHeading">
            <h2 id="ridersHeading" class="h-label">Riding <span id="riderCount" class="count"></span></h2>
            <ul class="rider-list" id="riderList"></ul>
          </section>
        </aside>
      </div>
    </div>

  </main>

  <!-- ---------- Money ---------- -->
  <main id="moneyView" class="view" hidden>
    <section class="money-head">
      <h1 class="balance-title" id="balanceTitle"></h1>
      <p class="balance-meta" id="balanceMeta"></p>
    </section>

    <div class="money-grid">
      <section class="panel" aria-labelledby="settleHeading">
        <h2 id="settleHeading" class="h-label">Settle up</h2>
        <ul class="transfers" id="transferList"></ul>
        <details class="record" id="recordBox">
          <summary class="btn btn-ghost">Record a payment</summary>
          <form id="settleForm" class="stack">
            <div class="settle-row">
              <label class="field"><span>From</span><select id="settleFrom"></select></label>
              <svg class="icon settle-arrow" viewBox="0 0 24 24" aria-hidden="true"><use href="#i-right"/></svg>
              <label class="field"><span>To</span><select id="settleTo"></select></label>
            </div>
            <div class="settle-row">
              <label class="field"><span>Amount (R$)</span><input id="settleAmount" type="number" min="0.01" step="0.01" inputmode="decimal" required></label>
              <label class="field field-grow"><span>Note <small>optional</small></span><input id="settleNote" type="text" maxlength="80" placeholder="Pix, cash…"></label>
            </div>
            <p class="form-msg" id="settleMsg" role="alert"></p>
            <button type="submit" class="btn btn-primary">Save payment</button>
          </form>
        </details>
      </section>

      <section class="panel" aria-labelledby="balancesHeading">
        <h2 id="balancesHeading" class="h-label">Everyone</h2>
        <ul class="balances" id="balanceList"></ul>
      </section>

      <section class="panel panel-wide" aria-labelledby="historyHeading">
        <h2 id="historyHeading" class="h-label">History</h2>
        <ol class="history" id="historyList"></ol>
      </section>
    </div>
  </main>
</div>

<div class="toast" id="toast" role="status" aria-live="polite" hidden></div>

<!-- ============ Icons (one stroke family, 1.75px) ============ -->
<svg width="0" height="0" style="position:absolute" aria-hidden="true">
  <defs>
    <symbol id="i-car" viewBox="0 0 24 24"><path d="M5 17h14M4 17v-4l2-5h12l2 5v4M4 13h16M7.5 17v2M16.5 17v2"/><circle cx="7.5" cy="14.8" r=".6"/><circle cx="16.5" cy="14.8" r=".6"/></symbol>
    <symbol id="i-coins" viewBox="0 0 24 24"><ellipse cx="9" cy="7" rx="5" ry="2.5"/><path d="M4 7v4c0 1.4 2.2 2.5 5 2.5s5-1.1 5-2.5V7"/><path d="M10 15.5c.9 1.2 2.8 2 5 2 2.8 0 5-1.1 5-2.5v-4c0-1.4-2.2-2.5-5-2.5"/></symbol>
    <symbol id="i-logout" viewBox="0 0 24 24"><path d="M14 4h4a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2h-4M10 16l-4-4 4-4M6 12h10"/></symbol>
    <symbol id="i-left" viewBox="0 0 24 24"><path d="M15 5l-7 7 7 7"/></symbol>
    <symbol id="i-right" viewBox="0 0 24 24"><path d="M9 5l7 7-7 7"/></symbol>
    <symbol id="i-calendar" viewBox="0 0 24 24"><rect x="4" y="5" width="16" height="15" rx="2"/><path d="M4 10h16M9 3v4M15 3v4"/></symbol>
    <symbol id="i-check" viewBox="0 0 24 24"><path d="M5 12.5l4.5 4.5L19 7.5"/></symbol>
    <symbol id="i-x" viewBox="0 0 24 24"><path d="M6 6l12 12M18 6L6 18"/></symbol>
    <symbol id="i-plus" viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></symbol>
    <symbol id="i-lock" viewBox="0 0 24 24"><rect x="5" y="11" width="14" height="9" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/></symbol>
    <symbol id="i-wheel" viewBox="0 0 24 24"><circle cx="12" cy="12" r="8"/><circle cx="12" cy="12" r="2"/><path d="M4.5 10.5h5.5M14 10.5h5.5M12 14v6"/></symbol>
    <symbol id="i-pin" viewBox="0 0 24 24"><path d="M12 21s-6-5.5-6-11a6 6 0 0 1 12 0c0 5.5-6 11-6 11z"/><circle cx="12" cy="10" r="2"/></symbol>
    <symbol id="i-swap" viewBox="0 0 24 24"><path d="M7 7h12l-3-3M17 17H5l3 3"/></symbol>
    <symbol id="i-trash" viewBox="0 0 24 24"><path d="M5 7h14M10 7V5h4v2M7 7l1 13h8l1-13"/></symbol>
    <symbol id="i-undo" viewBox="0 0 24 24"><path d="M9 14L4 9l5-5"/><path d="M4 9h10a6 6 0 0 1 0 12h-3"/></symbol>
    <symbol id="i-user-plus" viewBox="0 0 24 24"><circle cx="10" cy="8" r="4"/><path d="M3 20c.8-3.5 3.6-5.5 7-5.5s6.2 2 7 5.5M19 8v6M16 11h6"/></symbol>
  </defs>
</svg>

<script src="assets/app.js?v=<?= filemtime(__DIR__ . "/../assets/app.js") ?>" defer></script>
</body>
</html>
