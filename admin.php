<?php
require __DIR__ . '/partials.php';

/* ---------- Admin login ---------- */
if (!is_admin()) {
    $error = null;
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        check_csrf();
        if (ADMIN_PASSWORD === '') {
            $error = 'The admin password has not been set up yet. Copy config.example.php to config.php and choose a password.';
        } elseif (hash_equals(ADMIN_PASSWORD, (string)($_POST['password'] ?? ''))) {
            session_regenerate_id(true);
            $_SESSION['admin'] = true;
            flash('ok', 'Welcome, organizer!');
            redirect('admin.php');
        } else {
            $error = 'Wrong admin password.';
        }
    }
    page_head('Admin', 'admin.php'); ?>
  <main class="scroll">
    <div class="narrow">
      <h2><span class="seal">官</span> Admin Login</h2>
      <p class="sub">For the tournament organizer only.</p>
      <?php show_flashes(); ?>
      <?php if ($error): ?><div class="alert bad" role="alert"><span class="big">!</span><div><?= e($error) ?></div></div><?php endif; ?>
      <form method="post" action="admin.php">
        <?= csrf_field() ?>
        <div class="field">
          <label for="password">Admin password</label>
          <input type="password" id="password" name="password" required autocomplete="current-password">
        </div>
        <button type="submit" class="btn">⚙️ Enter</button>
      </form>
    </div>
  </main>
<?php page_foot();
    exit;
}

/* ---------- Admin actions ---------- */
$teams = load_teams();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'draw') {
        if (count($teams) < 2) {
            flash('bad', 'You need at least 2 teams to draw the bracket.');
        } else {
            db_update('bracket', function (&$bracket) use ($teams) {
                $bracket = new_bracket(array_keys($teams));
            });
            flash('ok', 'The bracket has been drawn! Teams were placed at random.');
        }
    } elseif ($action === 'clear_score') {
        reset_match((int)$_POST['round'], (int)$_POST['match']);
        flash('ok', 'All hands of that match were cleared. Later matches that depended on it were reset.');
    } elseif ($action === 'reset') {
        db_update('bracket', function (&$bracket) { $bracket = []; });
        flash('ok', 'The bracket was deleted. Teams are kept — you can draw a new one.');
    } elseif ($action === 'set_pin') {
        $pin = (string)($_POST['pin'] ?? '');
        if (!preg_match('/^\d{4,8}$/', $pin)) {
            flash('bad', 'A PIN must be 4 to 8 digits.');
        } else {
            db_update('registrations', function (&$all) use ($pin) {
                foreach ($all as &$t) if (($t['id'] ?? '') === $_POST['team']) $t['pin_hash'] = password_hash($pin, PASSWORD_DEFAULT);
            });
            flash('ok', 'New PIN set for ' . team_name($teams, $_POST['team']) . '.');
        }
    } elseif ($action === 'delete_team') {
        db_update('registrations', function (&$all) {
            $all = array_values(array_filter($all, function ($t) { return ($t['id'] ?? '') !== $_POST['team']; }));
        });
        flash('ok', 'Team ' . team_name($teams, $_POST['team']) . ' was removed.');
    }
    redirect('admin.php');
}

$bracket = load_bracket();
$total = $bracket ? count($bracket['rounds']) : 0;
$champ = champion($bracket);
$inBracket = [];
if ($bracket) foreach ($bracket['rounds'][0] as $m) { $inBracket[$m['a']] = true; if ($m['b']) $inBracket[$m['b']] = true; }
$late = $bracket ? count(array_diff_key($teams, $inBracket)) : 0;

page_head('Admin', 'admin.php');
?>
  <main class="scroll">
    <h2><span class="seal">官</span> Organizer Panel</h2>
    <p class="sub"><?= count($teams) ?> teams registered<?= $bracket ? ' · bracket drawn ' . e($bracket['created']) : ' · no bracket yet' ?><?= $champ ? ' · champion: ' . e(team_name($teams, $champ)) : '' ?></p>
    <?php show_flashes(); ?>

    <div class="section">
      <h3>🎲 Knockout Draw</h3>
      <p class="hint" style="margin-top:0">Pressing the button places every registered team into a knockout bracket at random. If the number of teams isn't 2, 4, 8, 16 or 32, some teams get a bye and go straight to round 2. Winners move forward automatically when scores are entered.</p>
      <?php if ($late): ?>
        <div class="alert bad"><span class="big">!</span><div><?= $late ?> team(s) registered after the draw and are not in the bracket. Redraw to include them.</div></div>
      <?php endif; ?>
      <div class="admin-actions">
        <form method="post" data-confirm="<?= e($bracket ? 'Redraw the bracket? ALL current results will be erased.' : 'Draw the bracket with all ' . count($teams) . ' teams now?') ?>">
          <?= csrf_field() ?><input type="hidden" name="action" value="draw">
          <button type="submit" class="btn">🐉 <?= $bracket ? 'Redraw Bracket' : 'Draw Bracket' ?></button>
        </form>
        <?php if ($bracket): ?>
          <a class="btn ghost" href="bracket.php">🏆 View bracket</a>
          <form method="post" data-confirm="Delete the bracket and all results? Teams will be kept. This cannot be undone.">
            <?= csrf_field() ?><input type="hidden" name="action" value="reset">
            <button type="submit" class="btn ghost">🧹 Delete bracket</button>
          </form>
        <?php endif; ?>
      </div>
    </div>

    <?php if ($bracket): ?>
    <div class="section">
      <h3>📝 Scores</h3>
      <p class="hint" style="margin-top:0">Open any match's score sheet to add hands or undo the last one. A match ends when a team reaches <?= TARGET_SCORE ?>. If a correction changes who won, later matches that depended on it are reset.</p>
      <?php search_box('#admin-matches', 'Search a team…'); ?>
      <div id="admin-matches">
        <?php for ($r = 0; $r < $total; $r++): ?>
          <p class="hint" style="margin:18px 0 6px"><strong><?= e(round_name($r, $total)) ?></strong></p>
          <?php foreach ($bracket['rounds'][$r] as $i => $m): ?>
            <?php render_match($m, $teams, $r, $i, true); ?>
            <?php if (!empty($m['hands'])): ?>
              <form method="post" class="mini-form" style="justify-content:flex-end;margin:-6px 0 12px" data-confirm="Clear ALL hands of this match?" data-search="<?= e(team_name($teams, $m['a']) . ' ' . team_name($teams, $m['b'])) ?>">
                <?= csrf_field() ?><input type="hidden" name="action" value="clear_score">
                <input type="hidden" name="round" value="<?= $r ?>"><input type="hidden" name="match" value="<?= $i ?>">
                <button type="submit" class="del">Clear all hands</button>
              </form>
            <?php endif; ?>
          <?php endforeach; ?>
        <?php endfor; ?>
        <div class="no-results">No matches for that search.</div>
      </div>
    </div>
    <?php endif; ?>

    <div class="section">
      <h3>👥 Teams &amp; Contacts</h3>
      <?php search_box('#admin-teams', 'Search teams, players or phone numbers…'); ?>
      <div class="table-scroll" id="admin-teams">
        <table class="teams">
          <thead><tr><th>Team</th><th>Players</th><th>Phone</th><th>Manage</th></tr></thead>
          <tbody>
          <?php foreach ($teams as $id => $t): ?>
            <tr data-search="<?= e($t['team'] . ' ' . $t['p1'] . ' ' . $t['p2'] . ' ' . ($t['phone'] ?? '')) ?>">
              <td><strong><?= e($t['team']) ?></strong><?php if (empty($t['pin_hash'])): ?><br><span class="badge">no PIN</span><?php endif; ?>
                <?php if (!empty($t['notes'])): ?><div class="hint"><?= e($t['notes']) ?></div><?php endif; ?></td>
              <td><?= e($t['p1']) ?> &amp; <?= e($t['p2']) ?></td>
              <td><?php if (!empty($t['phone'])): ?><a class="link" href="tel:<?= e(preg_replace('/[^0-9+]/', '', $t['phone'])) ?>"><?= e($t['phone']) ?></a><?php else: ?>—<?php endif; ?></td>
              <td>
                <form method="post" class="mini-form">
                  <?= csrf_field() ?><input type="hidden" name="action" value="set_pin"><input type="hidden" name="team" value="<?= e($id) ?>">
                  <input type="text" name="pin" inputmode="numeric" maxlength="8" placeholder="New PIN" aria-label="New PIN for <?= e($t['team']) ?>">
                  <button type="submit">Set</button>
                </form>
                <form method="post" class="mini-form" style="margin-top:6px" data-confirm="Remove team <?= e($t['team']) ?>? Their old match results will show as a removed team.">
                  <?= csrf_field() ?><input type="hidden" name="action" value="delete_team"><input type="hidden" name="team" value="<?= e($id) ?>">
                  <button type="submit" class="del">Remove team</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        <div class="no-results">No team matches your search.</div>
      </div>
    </div>
  </main>
<?php page_foot();
