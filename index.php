<?php
require __DIR__ . '/partials.php';

if (current_team_id()) redirect('dashboard.php');

$values = ['team' => '', 'p1' => '', 'p2' => '', 'phone' => '', 'notes' => ''];
$errors = [];
$teams = load_teams();
$full = count($teams) >= MAX_TEAMS;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$full) {
    check_csrf();
    foreach ($values as $k => $_) {
        $values[$k] = trim((string)($_POST[$k] ?? ''));
    }
    $pin = (string)($_POST['pin'] ?? '');
    $pin2 = (string)($_POST['pin2'] ?? '');

    if ($values['team'] === '') $errors['team'] = 'Please give your team a name.';
    elseif (mb_strlen($values['team']) > 40) $errors['team'] = 'Keep it under 40 characters.';
    elseif (find_team_by_name($teams, $values['team'])) $errors['team'] = 'A team with this name is already registered.';
    if ($values['p1'] === '') $errors['p1'] = 'Player 1 name is required.';
    if ($values['p2'] === '') $errors['p2'] = 'Player 2 name is required.';
    if ($values['phone'] === '') $errors['phone'] = 'Please enter a phone number.';
    elseif (!preg_match('/^[0-9 +()\-]{6,20}$/', $values['phone'])) $errors['phone'] = 'That phone number looks odd. Use digits, spaces, + or -.';
    if (!preg_match('/^\d{4,8}$/', $pin)) $errors['pin'] = 'Choose a PIN of 4 to 8 digits.';
    elseif ($pin !== $pin2) $errors['pin2'] = 'The two PINs do not match.';
    if (empty($_POST['rules'])) $errors['rules'] = 'Please accept the tournament rules.';

    if (!$errors) {
        $team = $values + [
            'id' => bin2hex(random_bytes(4)),
            'pin_hash' => password_hash($pin, PASSWORD_DEFAULT),
            'registered' => date('Y-m-d H:i'),
        ];
        $taken = db_update('registrations', function (&$all) use ($team) {
            // Re-check inside the lock in case two teams submit at once
            foreach ($all as $t) {
                if (mb_strtolower($t['team']) === mb_strtolower($team['team'])) return true;
            }
            $all[] = $team;
            return false;
        });
        if ($taken) {
            $errors['team'] = 'A team with this name is already registered.';
        } else {
            session_regenerate_id(true);
            $_SESSION['team_id'] = $team['id'];
            flash('ok', "You're in! Team “{$team['team']}” is registered and logged in. Remember your PIN to log in again later.");
            redirect('dashboard.php');
        }
    }
}

function field_class($errors, $k) { return 'field' . (isset($errors[$k]) ? ' error' : ''); }
function field_err($errors, $k) { return isset($errors[$k]) ? '<div class="err">' . e($errors[$k]) . '</div>' : ''; }

page_head('Registration', 'index.php', false);
?>
  <main class="scroll">
    <h2><span class="seal">報</span> Team Registration</h2>
    <p class="sub">Fill in the form below to enter the tournament. It takes less than a minute. Already registered? <a class="link" href="login.php">Log in →</a></p>

    <?php show_flashes(); ?>

    <?php if ($full): ?>
      <div class="alert bad"><span class="big">滿</span><div><strong>Registration is full.</strong> All <?= MAX_TEAMS ?> spots are taken — see you next time!</div></div>
    <?php else: ?>

    <?php if ($errors): ?>
      <div class="alert bad" role="alert">
        <span class="big">!</span>
        <div>Almost there — please fix the highlighted fields.</div>
      </div>
    <?php endif; ?>

    <form method="post" action="index.php" novalidate>
      <?= csrf_field() ?>
      <fieldset>
        <legend><span class="cardicon">隊</span> Your Team</legend>
        <div class="<?= field_class($errors, 'team') ?>">
          <label for="team">Team name <span class="req">*</span></label>
          <input type="text" id="team" name="team" maxlength="40" required value="<?= e($values['team']) ?>" placeholder="e.g. The Phoenix Rising">
          <div class="hint">You'll use this name to log in.</div>
          <?= field_err($errors, 'team') ?>
        </div>
        <div class="grid">
          <div class="<?= field_class($errors, 'p1') ?>">
            <label for="p1">Player 1 <span class="req">*</span></label>
            <input type="text" id="p1" name="p1" required value="<?= e($values['p1']) ?>" placeholder="Full name" autocomplete="name">
            <?= field_err($errors, 'p1') ?>
          </div>
          <div class="<?= field_class($errors, 'p2') ?>">
            <label for="p2">Player 2 <span class="req">*</span></label>
            <input type="text" id="p2" name="p2" required value="<?= e($values['p2']) ?>" placeholder="Partner's full name">
            <?= field_err($errors, 'p2') ?>
          </div>
        </div>
      </fieldset>

      <fieldset>
        <legend><span class="cardicon">鑰</span> Team PIN</legend>
        <div class="grid">
          <div class="<?= field_class($errors, 'pin') ?>">
            <label for="pin">Choose a PIN <span class="req">*</span></label>
            <input type="password" id="pin" name="pin" inputmode="numeric" maxlength="8" required placeholder="4–8 digits" autocomplete="new-password">
            <div class="hint">Share it with your partner so you can both log in.</div>
            <?= field_err($errors, 'pin') ?>
          </div>
          <div class="<?= field_class($errors, 'pin2') ?>">
            <label for="pin2">Repeat PIN <span class="req">*</span></label>
            <input type="password" id="pin2" name="pin2" inputmode="numeric" maxlength="8" required autocomplete="new-password">
            <?= field_err($errors, 'pin2') ?>
          </div>
        </div>
      </fieldset>

      <fieldset>
        <legend><span class="cardicon">信</span> Contact</legend>
        <div class="grid">
          <div class="<?= field_class($errors, 'phone') ?>">
            <label for="phone">Phone number <span class="req">*</span></label>
            <input type="tel" id="phone" name="phone" required value="<?= e($values['phone']) ?>" placeholder="+30 69…" autocomplete="tel">
            <div class="hint">Only the organizer sees this.</div>
            <?= field_err($errors, 'phone') ?>
          </div>
          <div class="field full">
            <label for="notes">Anything else? <small>(optional)</small></label>
            <textarea id="notes" name="notes" placeholder="Dietary needs, arrival time, questions…"><?= e($values['notes']) ?></textarea>
          </div>
        </div>
      </fieldset>

      <div class="<?= field_class($errors, 'rules') ?>">
        <label class="check">
          <input type="checkbox" name="rules" value="1" <?= !empty($_POST['rules']) ? 'checked' : '' ?>>
          <span>We agree to the tournament rules and promise to shuffle fairly. 🐉</span>
        </label>
        <?= field_err($errors, 'rules') ?>
      </div>

      <div class="actions">
        <button type="submit" class="btn">🀄 Register Team</button>
        <span class="hint"><?= MAX_TEAMS - count($teams) ?> of <?= MAX_TEAMS ?> spots left</span>
      </div>
    </form>
    <?php endif; ?>
  </main>
<?php page_foot();
