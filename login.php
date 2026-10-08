<?php
require __DIR__ . '/partials.php';

if (current_team_id()) redirect('dashboard.php');

$name = '';
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $name = trim((string)($_POST['team'] ?? ''));
    $pin = (string)($_POST['pin'] ?? '');
    $team = find_team_by_name(load_teams(), $name);

    if (!$team) {
        $error = 'No team with that name. Check the spelling, or register first.';
    } elseif (empty($team['pin_hash'])) {
        $error = 'This team has no PIN yet. Ask the organizer to set one for you.';
    } elseif (!password_verify($pin, $team['pin_hash'])) {
        $error = 'Wrong PIN. Try again, or ask the organizer to reset it.';
    } else {
        session_regenerate_id(true);
        $_SESSION['team_id'] = $team['id'];
        flash('ok', "Welcome back, {$team['team']}!");
        redirect('dashboard.php');
    }
}

page_head('Team Login', 'login.php');
?>
  <main class="scroll">
    <div class="narrow">
      <h2><span class="seal">入</span> Team Login</h2>
      <p class="sub">Log in to see the other teams, your matches, and enter your scores.</p>

      <?php show_flashes(); ?>
      <?php if ($error): ?>
        <div class="alert bad" role="alert"><span class="big">!</span><div><?= e($error) ?></div></div>
      <?php endif; ?>

      <form method="post" action="login.php">
        <?= csrf_field() ?>
        <div class="field">
          <label for="team">Team name</label>
          <input type="text" id="team" name="team" required value="<?= e($name) ?>" placeholder="Your team name" autocomplete="username">
        </div>
        <div class="field">
          <label for="pin">PIN</label>
          <input type="password" id="pin" name="pin" inputmode="numeric" required placeholder="••••" autocomplete="current-password">
        </div>
        <div class="actions">
          <button type="submit" class="btn">🔑 Log In</button>
          <a class="link" href="index.php">Register a new team</a>
        </div>
      </form>
      <p class="hint" style="margin-top:28px; text-align:center">Organizer? <a class="link" href="admin.php">Admin login</a></p>
    </div>
  </main>
<?php page_foot();
