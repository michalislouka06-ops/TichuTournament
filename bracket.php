<?php
require __DIR__ . '/partials.php';

require_login();

$teams = load_teams();
$bracket = load_bracket();
$me = current_team_id();

page_head('Bracket', 'bracket.php', true, true);
?>
  <?php if (!$bracket): ?>
    <main class="scroll">
      <h2><span class="seal">榜</span> Tournament Bracket</h2>
      <div class="empty"><span class="big">待</span>The organizer hasn't drawn the bracket yet. Check back soon!</div>
    </main>
  <?php else:
    $total = count($bracket['rounds']);
    $done = 0; $all = 0;
    foreach ($bracket['rounds'] as $round) foreach ($round as $m) {
        if ($m['bye']) continue;
        $all++;
        if ($m['winner']) $done++;
    }
  ?>
    <section class="board">
      <div class="board-head">
        <h2>Tournament <b>Bracket</b></h2>
        <p><?= count($teams) ?> teams · <?= $done ?> of <?= $all ?> matches played · drawn <?= e($bracket['created']) ?></p>
      </div>
      <div class="search"><input type="search" data-highlight=".bracket" placeholder="Search a team…" aria-label="Search a team in the bracket"></div>
      <?php render_bracket($bracket, $teams, $me); ?>
      <div class="legend">
        <span class="l-win">Winner — goes through</span>
        <span class="l-out">Knocked out</span>
        <?php if ($me): ?><span class="l-me">Your team</span><?php endif; ?>
        <span>Scroll sideways on small screens →</span>
      </div>
    </section>

    <main class="scroll">
      <h2><span class="seal">譜</span> All Match Results</h2>
      <?php search_box('#history', 'Search a team to see their path…'); ?>
      <div id="history">
        <?php for ($r = $total - 1; $r >= 0; $r--): ?>
          <p class="hint" style="margin:18px 0 6px"><strong><?= e(round_name($r, $total)) ?></strong></p>
          <?php foreach ($bracket['rounds'][$r] as $i => $m) render_match($m, $teams, $r, $i, false, $me); ?>
        <?php endfor; ?>
        <div class="no-results">No matches for that search.</div>
      </div>
    </main>
  <?php endif; ?>
<?php page_foot();
