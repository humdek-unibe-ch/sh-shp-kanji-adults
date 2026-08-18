<?php
/**
 * Regenerate content/kanji_labjs.study.json in lab.js **Builder** format.
 *
 * The SelfHelp lab_js plugin does not accept a runtime component tree. Its
 * loader (server/component/style/labJS/js/3_labJS.js) calls
 *
 *     makeComponentTree(exp.components, 'root')
 *
 * so the JSON must have the Builder's `.study.json` shape:
 *
 *   components          flat map of id => node, entry node keyed exactly 'root'
 *   children            arrays of component ids, not nested objects
 *   files               per node: [{localPath, poolPath}]
 *   files.files         top-level pool the loader dereferences by poolPath
 *   templateParameters  a grid: {columns:[{name,type}], rows:[[...]]}
 *   responses           [{label, event, target, filter}]
 *   messageHandlers     [{message, code}]
 *
 * Trial timings come from the Qualtrics QuestionJS: 500 ms fixation,
 * 5000 ms study screen.
 *
 * Run: php content/build_labjs.php
 */

$here = __DIR__;

const ASSET    = '{{ASSET_BASE}}';
const FIX_MS   = 500;
const LEARN_MS = 5000;

function readCsv($f) {
    $rows = []; $h = null;
    foreach (file($f, FILE_IGNORE_NEW_LINES) as $line) {
        $c = str_getcsv($line, ',', '"', '\\');
        if ($h === null) { $h = $c; continue; }
        if (count($c) !== count($h)) continue;
        $rows[] = array_combine($h, $c);
    }
    return $rows;
}

/** Builder grid: typed columns + row arrays. */
function grid(array $cols, array $rows) {
    return [
        'columns' => array_map(fn($c) => ['name' => $c, 'type' => 'string'], $cols),
        'rows'    => $rows,
    ];
}

$learn  = readCsv("$here/items_learn.csv");
$recall = readCsv("$here/items_recall.csv");
$byList = fn($rows, $l) => array_values(array_filter($rows, fn($r) => $r['list'] === $l));

/* ---------------------------------------------------------- asset pool */
$assets = [];
$add = function ($n) use (&$assets) { if ($n !== '' && !isset($assets[$n])) $assets[$n] = true; };
$add('Fixationskreuz.jpg');
foreach ($learn as $r)  { $add($r['kanji_img']); $add($r['meaning_img']); }
foreach ($recall as $r) { $add($r['kanji_img']); $add($r['correct_img']); $add($r['distractor_img']); }
foreach (['', '_EN', '_FR', '_IT'] as $s) {
    foreach (['unsicher', 'mittel', 'sicher'] as $c) $add("CJ_{$c}{$s}.png");
}
$assets = array_keys($assets);

// loadFiles() looks up obj.files.files[poolPath].content, so each pool entry
// carries the final URL under `content`.
$filePool = [];
$fileList = [];
foreach ($assets as $a) {
    $url = ASSET . '/' . $a;
    $filePool[$url] = ['content' => $url];
    $fileList[]     = ['localPath' => $a, 'poolPath' => $url];
}

/* ------------------------------------------------------------ builder */
$C   = [];
$uid = 0;
$mk = function ($node) use (&$C, &$uid) {
    $id = 'c' . (++$uid);
    $C[$id] = $node;
    return $id;
};

$fixation = function () use ($mk, $fileList) {
    return $mk([
        'title'   => 'Fixation',
        'type'    => 'lab.html.Screen',
        'content' => '<div class="kanji-stage"><img class="kanji-fix" src="${ this.files[\'Fixationskreuz.jpg\'] }"></div>',
        'timeout' => (string) FIX_MS,
        'files'   => $fileList,
        'responses' => [], 'messageHandlers' => [], 'parameters' => [],
    ]);
};

$learnLoop = function ($rows, $title, $shuffle) use ($mk, $fixation, $fileList) {
    $cols = ['list', 'trial', 'concept', 'kanji_img', 'meaning_img'];
    $data = array_map(fn($r) => [
        $r['list'], $r['trial'], $r['concept'], $r['kanji_img'], $r['meaning_img'],
    ], $rows);

    $stim = $mk([
        'title'   => 'Learn stimulus',
        'type'    => 'lab.html.Screen',
        // Kanji left, meaning right — matches the Qualtrics LA2 layout.
        'content' => '<div class="kanji-stage kanji-pair">'
                   . '<img class="kanji-item" src="${ this.files[this.parameters.kanji_img] }">'
                   . '<img class="kanji-item" src="${ this.files[this.parameters.meaning_img] }">'
                   . '</div>',
        'timeout' => (string) LEARN_MS,
        'files'   => $fileList,
        'responses' => [], 'messageHandlers' => [], 'parameters' => [],
    ]);

    $seq = $mk([
        'title'    => $title . ' trial',
        'type'     => 'lab.flow.Sequence',
        'children' => [$fixation(), $stim],
        'files' => [], 'responses' => [], 'messageHandlers' => [], 'parameters' => [],
    ]);

    return $mk([
        'title'    => $title,
        'type'     => 'lab.flow.Loop',
        'children' => [$seq],
        'templateParameters' => grid($cols, $data),
        'shuffle'  => $shuffle,
        'files' => [], 'responses' => [], 'messageHandlers' => [], 'parameters' => [],
    ]);
};

$recallLoop = function ($rows, $title, $shuffle) use ($mk, $fixation, $fileList) {
    $cols = ['list', 'trial', 'concept', 'kanji_img', 'correct_img', 'distractor_img', 'orig_pos'];
    $data = array_map(fn($r) => [
        $r['list'], $r['trial'], $r['concept'], $r['kanji_img'],
        $r['correct_img'], $r['distractor_img'], $r['correct_pos_original'],
    ], $rows);

    $choice = $mk([
        'title'   => 'Recall choice',
        'type'    => 'lab.html.Screen',
        'content' => '<div class="kanji-stage">'
                   . '<img class="kanji-cue" src="${ this.files[this.parameters.kanji_img] }">'
                   . '<div class="kanji-choices">'
                   . '<div class="kanji-opt" data-side="left"><img src="${ this.files[this.state.left_img] }"></div>'
                   . '<div class="kanji-opt" data-side="right"><img src="${ this.files[this.state.right_img] }"></div>'
                   . '</div></div>',
        'files'   => $fileList,
        'responses' => [
            ['label' => 'left',  'event' => 'click', 'target' => '.kanji-opt[data-side="left"]',  'filter' => ''],
            ['label' => 'right', 'event' => 'click', 'target' => '.kanji-opt[data-side="right"]', 'filter' => ''],
        ],
        'messageHandlers' => [
            // Randomise which side holds the correct answer. The Qualtrics
            // original fixed it per trial and blocked it 7-then-8, which is
            // exploitable; orig_pos is still recorded for comparability.
            ['message' => 'before:prepare', 'code' =>
                "const flip = Math.random() < 0.5;\n"
                . "this.state.correct_side = flip ? 'left' : 'right';\n"
                . "this.state.left_img  = flip ? this.parameters.correct_img : this.parameters.distractor_img;\n"
                . "this.state.right_img = flip ? this.parameters.distractor_img : this.parameters.correct_img;\n"],
            ['message' => 'after:end', 'code' =>
                "const side = this.state.response;\n"
                . "this.data.correct_side = this.state.correct_side;\n"
                . "this.data.chosen_side  = side;\n"
                . "this.data.chosen_img   = side === 'left' ? this.state.left_img : this.state.right_img;\n"
                . "this.data.correct      = side === this.state.correct_side ? 1 : 0;\n"],
        ],
        'parameters' => [],
    ]);

    // Confidence: 1 = unsicher, 2 = mittel, 3 = sicher (ascending), exactly as
    // the Qualtrics data-option values were ordered.
    $conf = $mk([
        'title'   => 'Confidence',
        'type'    => 'lab.html.Screen',
        'content' => '<div class="kanji-stage"><div class="kanji-choices kanji-cj">'
                   . '<div class="kanji-opt" data-cj="1"><img src="${ this.files[this.state.cj_unsicher] }"></div>'
                   . '<div class="kanji-opt" data-cj="2"><img src="${ this.files[this.state.cj_mittel] }"></div>'
                   . '<div class="kanji-opt" data-cj="3"><img src="${ this.files[this.state.cj_sicher] }"></div>'
                   . '</div></div>',
        'files'   => $fileList,
        'responses' => [
            ['label' => '1', 'event' => 'click', 'target' => '.kanji-opt[data-cj="1"]', 'filter' => ''],
            ['label' => '2', 'event' => 'click', 'target' => '.kanji-opt[data-cj="2"]', 'filter' => ''],
            ['label' => '3', 'event' => 'click', 'target' => '.kanji-opt[data-cj="3"]', 'filter' => ''],
        ],
        'messageHandlers' => [
            ['message' => 'before:prepare', 'code' =>
                "const lang = (window.KANJI_LANG || 'de');\n"
                . "const sfx = { de: '', en: '_EN', fr: '_FR', it: '_IT' }[lang] || '';\n"
                . "this.state.cj_unsicher = 'CJ_unsicher' + sfx + '.png';\n"
                . "this.state.cj_mittel   = 'CJ_mittel'   + sfx + '.png';\n"
                . "this.state.cj_sicher   = 'CJ_sicher'   + sfx + '.png';\n"],
            ['message' => 'after:end', 'code' =>
                "this.data.confidence = parseInt(this.state.response, 10);\n"],
        ],
        'parameters' => [],
    ]);

    $seq = $mk([
        'title'    => $title . ' trial',
        'type'     => 'lab.flow.Sequence',
        'children' => [$fixation(), $choice, $conf],
        'files' => [], 'responses' => [], 'messageHandlers' => [], 'parameters' => [],
    ]);

    return $mk([
        'title'    => $title,
        'type'     => 'lab.flow.Loop',
        'children' => [$seq],
        'templateParameters' => grid($cols, $data),
        'shuffle'  => $shuffle,
        'files' => [], 'responses' => [], 'messageHandlers' => [], 'parameters' => [],
    ]);
};

/** Persist progress to SelfHelp between blocks. */
$savePoint = function ($label, $trigger) use ($mk) {
    return $mk([
        'title'   => 'Save ' . $label,
        'type'    => 'lab.html.Screen',
        'content' => '<div class="kanji-stage"><p class="kanji-wait">…</p></div>',
        'timeout' => '50',
        'messageHandlers' => [
            ['message' => 'after:end', 'code' =>
                "if (typeof saveDataToSelfHelp === 'function') {\n"
                . "  saveDataToSelfHelp('" . $trigger . "', { block: '" . $label . "' });\n"
                . "}\n"],
        ],
        'files' => [], 'responses' => [], 'parameters' => [],
    ]);
};

$children = [
    $learnLoop($byList($learn, 'P'),   'Learn practice',  false),
    $recallLoop($byList($recall, 'P'), 'Recall practice', false),
    $savePoint('practice', 'updated'),
    $learnLoop($byList($learn, 'A'),   'Learn A',  true),
    $savePoint('learn_A', 'updated'),
    $recallLoop($byList($recall, 'A'), 'Recall A', true),
    $savePoint('recall_A', 'updated'),
    $learnLoop($byList($learn, 'B'),   'Learn B',  true),
    $savePoint('learn_B', 'updated'),
    $recallLoop($byList($recall, 'B'), 'Recall B', true),
    $savePoint('recall_B', 'finished'),
];

// The loader starts from the component keyed exactly 'root'.
$C['root'] = [
    'title'    => 'Kanji Adults',
    'type'     => 'lab.flow.Sequence',
    'children' => $children,
    'files' => [], 'responses' => [], 'messageHandlers' => [], 'parameters' => [],
];

$study = [
    'components' => $C,
    'files'      => ['files' => $filePool],
    'version'    => [22, 1, 0],
];

file_put_contents("$here/kanji_labjs.study.json",
    json_encode($study, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

printf("components : %d (entry 'root': %s)\n", count($C), isset($C['root']) ? 'present' : 'MISSING');
printf("pool files : %d\n", count($filePool));
$loops = 0; $trials = 0;
foreach ($C as $n) {
    if (($n['type'] ?? '') === 'lab.flow.Loop') {
        $loops++;
        $trials += count($n['templateParameters']['rows']);
    }
}
printf("loops      : %d, %d trials\n", $loops, $trials);
