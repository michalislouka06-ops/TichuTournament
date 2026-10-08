<?php
// Config, storage, auth and tournament logic shared by every page.

const TOURNAMENT_NAME = 'Dragon Tichu Cup';
const TOURNAMENT_DATE = 'Saturday, 14 November 2026';
const TOURNAMENT_PLACE = 'Golden Lantern Hall';
const MAX_TEAMS = 32;
const TARGET_SCORE = 1000; // a match ends when a team reaches this

// The admin password lives in config.php, which is kept out of git.
// To set one up, copy config.example.php to config.php and change the password.
if (is_file(__DIR__ . '/config.php')) require_once __DIR__ . '/config.php';
if (!defined('ADMIN_PASSWORD')) define('ADMIN_PASSWORD', '');

const DATA_DIR = __DIR__ . '/data';

session_name('tichu_tournament');
session_start();

function e($s) {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function redirect($to) {
    header('Location: ' . $to);
    exit;
}

/* ---------- Flash messages ---------- */

function flash($type, $msg) {
    $_SESSION['flash'][] = [$type, $msg];
}

function take_flashes() {
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $f;
}

/* ---------- CSRF ---------- */

function csrf_token() {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
    return $_SESSION['csrf'];
}

function csrf_field() {
    return '<input type="hidden" name="csrf" value="' . csrf_token() . '">';
}

function check_csrf() {
    if (!hash_equals(csrf_token(), (string)($_POST['csrf'] ?? ''))) {
        http_response_code(400);
        exit('Your session expired. Please go back and try again.');
    }
}

/* ---------- JSON storage ---------- */

function db_read($name) {
    $file = DATA_DIR . "/$name.json";
    if (!is_file($file)) return [];
    $data = json_decode(file_get_contents($file), true);
    return is_array($data) ? $data : [];
}

// Runs $fn(&$data) under an exclusive lock and saves the result.
function db_update($name, callable $fn) {
    if (!is_dir(DATA_DIR)) mkdir(DATA_DIR, 0775, true);
    $fp = fopen(DATA_DIR . "/$name.json", 'c+');
    flock($fp, LOCK_EX);
    $data = json_decode(stream_get_contents($fp), true);
    if (!is_array($data)) $data = [];
    $result = $fn($data);
    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    flock($fp, LOCK_UN);
    fclose($fp);
    return $result;
}

/* ---------- Teams ---------- */

function load_teams() {
    $teams = db_read('registrations');
    // Older entries had no id; give them a stable one.
    $missing = false;
    foreach ($teams as $t) if (empty($t['id'])) $missing = true;
    if ($missing) {
        db_update('registrations', function (&$all) {
            foreach ($all as &$t) if (empty($t['id'])) $t['id'] = bin2hex(random_bytes(4));
        });
        $teams = db_read('registrations');
    }
    $byId = [];
    foreach ($teams as $t) $byId[$t['id']] = $t;
    return $byId;
}

function find_team_by_name($teams, $name) {
    foreach ($teams as $t) {
        if (mb_strtolower(trim($t['team'])) === mb_strtolower(trim($name))) return $t;
    }
    return null;
}

function team_name($teams, $id) {
    return isset($teams[$id]) ? $teams[$id]['team'] : '(removed team)';
}

/* ---------- Auth ---------- */

function current_team_id() {
    return $_SESSION['team_id'] ?? null;
}

function is_admin() {
    return !empty($_SESSION['admin']);
}

function require_login() {
    if (!current_team_id() && !is_admin()) {
        flash('bad', 'Please log in with your team name first.');
        redirect('login.php');
    }
}

function require_admin() {
    if (!is_admin()) redirect('admin.php');
}

/* ---------- Knockout bracket ---------- */
// bracket.json: ['created', 'size', 'rounds' => [[match, ...], ...]]
// Round 0 holds size/2 matches, each later round half as many; the last is the final.
// Match winners feed round r+1, match floor(i/2): even i into slot a, odd i into slot b.

function load_bracket() {
    return db_read('bracket');
}

function blank_match($a = null, $b = null, $bye = false) {
    return ['a' => $a, 'b' => $b, 'sa' => null, 'sb' => null, 'winner' => null, 'bye' => $bye, 'hands' => []];
}

function round_name($r, $totalRounds) {
    $left = $totalRounds - $r;
    $names = [1 => 'Final', 2 => 'Semi-finals', 3 => 'Quarter-finals'];
    return $names[$left] ?? 'Round of ' . (2 ** $left);
}

function new_bracket(array $teamIds) {
    shuffle($teamIds);
    $n = count($teamIds);
    $size = 2;
    while ($size < $n) $size *= 2;
    $first = intdiv($size, 2);
    $byes = $size - $n;

    // Spread the byes evenly so no first-round match is empty
    $byeAt = [];
    for ($j = 0; $j < $byes; $j++) $byeAt[intdiv($j * $first, $byes)] = true;

    $round = [];
    for ($i = 0; $i < $first; $i++) {
        $a = array_shift($teamIds);
        $b = isset($byeAt[$i]) ? null : array_shift($teamIds);
        $round[] = blank_match($a, $b, isset($byeAt[$i]));
    }
    $bracket = ['created' => date('Y-m-d H:i'), 'size' => $size, 'rounds' => [$round]];
    for ($m = intdiv($first, 2); $m >= 1; $m = intdiv($m, 2)) {
        $bracket['rounds'][] = array_fill(0, $m, blank_match());
    }
    bracket_resolve($bracket);
    return $bracket;
}

// Recomputes who plays whom, the running totals and who won, from the first round forward.
// A match whose players changed (because an earlier result was corrected) is reset.
function bracket_resolve(array &$bracket) {
    $rounds = &$bracket['rounds'];
    for ($r = 0; $r < count($rounds); $r++) {
        for ($i = 0; $i < count($rounds[$r]); $i++) {
            $m = &$rounds[$r][$i];
            if ($r > 0) {
                $a = $rounds[$r - 1][2 * $i]['winner'];
                $b = $rounds[$r - 1][2 * $i + 1]['winner'];
                if ($m['a'] !== $a || $m['b'] !== $b) {
                    $m = blank_match($a, $b);
                }
            }
            $m['hands'] = $m['hands'] ?? [];
            [$m['sa'], $m['sb']] = match_totals($m);
            if ($m['bye']) $m['winner'] = $m['a'];
            elseif (match_finished($m)) $m['winner'] = $m['sa'] > $m['sb'] ? $m['a'] : $m['b'];
            else $m['winner'] = null;
            unset($m);
        }
    }
}

function champion($bracket) {
    if (!$bracket) return null;
    $final = end($bracket['rounds'])[0];
    return $final['winner'];
}

/* ---------- Hand-by-hand scoring (like iTichu) ---------- */
// A hand stores team A's card points plus each team's calls:
// ['card' => 35, 'a' => ['tichu' => 1, 'grand' => 0, 'double' => false], 'b' => [...]]
// tichu/grand: 1 = made it, -1 = called and failed, 0 = no call.

const CALLS = ['tichu' => 100, 'grand' => 200];

function hand_points($h) {
    if ($h['a']['double']) [$pa, $pb] = [200, 0];
    elseif ($h['b']['double']) [$pa, $pb] = [0, 200];
    else [$pa, $pb] = [$h['card'], 100 - $h['card']];
    foreach (CALLS as $call => $value) {
        $pa += $h['a'][$call] * $value;
        $pb += $h['b'][$call] * $value;
    }
    return [$pa, $pb];
}

function match_totals($m) {
    if (empty($m['hands'])) return [null, null];
    $sa = $sb = 0;
    foreach ($m['hands'] as $h) {
        [$pa, $pb] = hand_points($h);
        $sa += $pa;
        $sb += $pb;
    }
    return [$sa, $sb];
}

// Finished once a team reaches the target and the totals are not level.
function match_finished($m) {
    return $m['sa'] !== null && max($m['sa'], $m['sb']) >= TARGET_SCORE && $m['sa'] !== $m['sb'];
}

// Builds a hand from the score-sheet form. Returns [hand, error].
function hand_from_post($post) {
    $h = [];
    foreach (['a', 'b'] as $t) {
        $h[$t] = ['double' => !empty($post[$t . '_double'])];
        foreach (array_keys(CALLS) as $call) {
            $ok = !empty($post["{$t}_{$call}_ok"]);
            $fail = !empty($post["{$t}_{$call}_fail"]);
            if ($ok && $fail) return [null, 'A call cannot be both made and failed — pick one.'];
            $h[$t][$call] = $ok ? 1 : ($fail ? -1 : 0);
        }
    }
    if ($h['a']['double'] && $h['b']['double']) return [null, 'Only one team can make a double win (1-2).'];

    // Only the first player out can make their call
    $made = [];
    foreach (['a', 'b'] as $t) foreach (array_keys(CALLS) as $call) if ($h[$t][$call] === 1) $made[] = $t;
    if (count($made) > 1) return [null, 'Only one player goes out first, so only one Tichu / Grand Tichu can succeed per hand.'];
    foreach (['a', 'b'] as $t) {
        $other = $t === 'a' ? 'b' : 'a';
        if ($h[$t]['double'] && $made && $made[0] === $other) {
            return [null, 'A team with a double win went out first, so the other team cannot make its Tichu.'];
        }
    }

    if ($h['a']['double'] || $h['b']['double']) {
        $h['card'] = 0;
    } else {
        $card = filter_var($post['card'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => -25, 'max_range' => 125]]);
        if ($card === false || $card % 5 !== 0) return [null, 'Card points must be between -25 and 125, in steps of 5.'];
        $h['card'] = $card;
    }
    return [$h, null];
}

// Adds a hand to a match. $teamId limits it to that team's own match (null = admin).
// $expected is how many hands the form saw, so two phones can't add the same hand twice.
function add_hand($r, $i, $hand, $byLabel, $teamId, $expected) {
    return db_update('bracket', function (&$bracket) use ($r, $i, $hand, $byLabel, $teamId, $expected) {
        if (!isset($bracket['rounds'][$r][$i])) return 'Match not found.';
        $m = &$bracket['rounds'][$r][$i];
        if ($m['bye'] || $m['a'] === null || $m['b'] === null) return 'This match is not ready to be played yet.';
        if ($teamId !== null && $teamId !== $m['a'] && $teamId !== $m['b']) return 'You can only score your own matches.';
        if ($m['winner'] !== null) return 'This match is already finished.';
        if (count($m['hands'] ?? []) !== (int)$expected) return 'Someone else just added a hand. Check the list below before adding yours.';
        $m['hands'][] = $hand + ['by' => $byLabel, 'at' => date('H:i')];
        unset($m);
        bracket_resolve($bracket);
        return null;
    });
}

// Removes the last hand. Teams can only do this before the next round has started.
function undo_hand($r, $i, $teamId) {
    return db_update('bracket', function (&$bracket) use ($r, $i, $teamId) {
        if (!isset($bracket['rounds'][$r][$i])) return 'Match not found.';
        $m = &$bracket['rounds'][$r][$i];
        if (empty($m['hands'])) return 'There is no hand to remove.';
        if ($teamId !== null) {
            if ($teamId !== $m['a'] && $teamId !== $m['b']) return 'You can only score your own matches.';
            $next = $bracket['rounds'][$r + 1][intdiv($i, 2)] ?? null;
            if ($next && !empty($next['hands'])) return 'The next round has already started. Ask the organizer to correct this.';
        }
        array_pop($m['hands']);
        unset($m);
        bracket_resolve($bracket);
        return null;
    });
}

function reset_match($r, $i) {
    db_update('bracket', function (&$bracket) use ($r, $i) {
        if (!isset($bracket['rounds'][$r][$i])) return;
        $bracket['rounds'][$r][$i]['hands'] = [];
        bracket_resolve($bracket);
    });
}

/* ---------- Tournament-wide statistics ---------- */

// Adds up every hand of every match, per team, plus a few tournament highlights.
function tournament_stats($bracket, $teams) {
    $blank = ['won' => 0, 'lost' => 0, 'matches' => 0, 'hands' => 0, 'for' => 0, 'against' => 0,
              'tichu_ok' => 0, 'tichu_fail' => 0, 'grand_ok' => 0, 'grand_fail' => 0, 'double' => 0, 'best_hand' => null];
    $rows = [];
    foreach ($teams as $id => $t) $rows[$id] = ['id' => $id, 'team' => $t['team']] + $blank;
    $hi = ['best_hand' => null, 'biggest_win' => null, 'top_score' => null];
    $total = $bracket ? count($bracket['rounds']) : 0;

    foreach ($bracket['rounds'] ?? [] as $r => $round) {
        foreach ($round as $m) {
            if ($m['bye'] || empty($m['hands'])) continue;
            $done = $m['winner'] !== null;
            foreach (['a' => 'b', 'b' => 'a'] as $t => $o) {
                $id = $m[$t];
                if (!isset($rows[$id])) continue;
                $row = &$rows[$id];
                $row['matches']++;
                if ($done) $m['winner'] === $id ? $row['won']++ : $row['lost']++;
                $row['for'] += $m['s' . $t];
                $row['against'] += $m['s' . $o];
                unset($row);
            }
            foreach ($m['hands'] as $n => $h) {
                $pts = array_combine(['a', 'b'], hand_points($h));
                foreach (['a', 'b'] as $t) {
                    $id = $m[$t];
                    if (!isset($rows[$id])) continue;
                    $row = &$rows[$id];
                    $row['hands']++;
                    if ($h[$t]['tichu'] === 1) $row['tichu_ok']++;
                    if ($h[$t]['tichu'] === -1) $row['tichu_fail']++;
                    if ($h[$t]['grand'] === 1) $row['grand_ok']++;
                    if ($h[$t]['grand'] === -1) $row['grand_fail']++;
                    if ($h[$t]['double']) $row['double']++;
                    if ($row['best_hand'] === null || $pts[$t] > $row['best_hand']) $row['best_hand'] = $pts[$t];
                    unset($row);
                    if ($hi['best_hand'] === null || $pts[$t] > $hi['best_hand']['pts']) {
                        $hi['best_hand'] = ['pts' => $pts[$t], 'team' => $id, 'round' => round_name($r, $total), 'hand' => $n + 1];
                    }
                }
            }
            $top = max($m['sa'], $m['sb']);
            $topTeam = $m['sa'] >= $m['sb'] ? $m['a'] : $m['b'];
            if ($hi['top_score'] === null || $top > $hi['top_score']['pts']) {
                $hi['top_score'] = ['pts' => $top, 'team' => $topTeam, 'round' => round_name($r, $total)];
            }
            if ($done) {
                $margin = abs($m['sa'] - $m['sb']);
                if ($hi['biggest_win'] === null || $margin > $hi['biggest_win']['margin']) {
                    $loser = $m['winner'] === $m['a'] ? $m['b'] : $m['a'];
                    $hi['biggest_win'] = ['margin' => $margin, 'team' => $m['winner'], 'loser' => $loser,
                                          'score' => max($m['sa'], $m['sb']) . '–' . min($m['sa'], $m['sb']), 'round' => round_name($r, $total)];
                }
            }
        }
    }

    // Team status in the bracket
    $champ = champion($bracket);
    foreach ($rows as $id => &$row) {
        $calls = $row['tichu_ok'] + $row['tichu_fail'] + $row['grand_ok'] + $row['grand_fail'];
        $row['calls'] = $calls;
        $row['call_rate'] = $calls ? round(100 * ($row['tichu_ok'] + $row['grand_ok']) / $calls) : null;
        $row['diff'] = $row['for'] - $row['against'];
        $row['status'] = $champ === $id ? 'champion' : ($row['lost'] ? 'out' : 'in');
    }
    unset($row);

    usort($rows, function ($x, $y) {
        return [$y['won'], $y['diff'], $y['for']] <=> [$x['won'], $x['diff'], $x['for']];
    });

    $sum = function ($key) use ($rows) { return array_sum(array_column($rows, $key)); };
    $totals = [
        'hands' => intdiv($sum('hands'), 2),
        'matches' => intdiv($sum('matches'), 2),
        'tichu_ok' => $sum('tichu_ok'), 'tichu_fail' => $sum('tichu_fail'),
        'grand_ok' => $sum('grand_ok'), 'grand_fail' => $sum('grand_fail'),
        'double' => $sum('double'),
    ];
    $leader = function ($key) use ($rows) {
        $best = null;
        foreach ($rows as $row) if ($row[$key] > 0 && ($best === null || $row[$key] > $best[$key])) $best = $row;
        return $best;
    };
    $hi['most_tichus'] = $leader('tichu_ok');
    $hi['most_grands'] = $leader('grand_ok');
    $hi['most_doubles'] = $leader('double');

    return [$rows, $totals, $hi];
}
