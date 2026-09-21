<?php
// The code baseline is shipped with the image, not captured from the container.
//
// `code-unchanged` is the check that says this container is still running the
// code that was deployed. It could not say that. The baseline it compared
// against was produced by walking the container's own tree once, when the
// observer started -- so a tree altered before the observer started, or on the
// boot after a bounce, baselined as intended and every later cycle agreed with
// it. The check's real claim was "nothing changed while I was watching", which
// is a much smaller thing than its name.
//
// That is the boot-only edge the impersonation chain rides. A party that can
// write a member's container ends the observer beside it, alters the tree, and
// the honest observer that comes back re-baselines the altered tree and
// reports a healthy ring for as long as it runs.
//
// The manifest is now produced when the image is built, from the code that
// went into the image, and shipped inside it. It is written by the build --
// which runs as root -- and left read-only, so the unprivileged user the work
// runs as cannot rewrite it to match a tree it has altered.
//
// **It does not close the chain.** Establishing that an image was the intended
// image needs provenance from outside the deployment, and nothing here
// provides that: a tampered *image* still ships a manifest of its own tampered
// contents. What it closes is the gap between the image being built and the
// observer starting, which is where the alteration was free.
//
// The other half of failing closed: a baseline that is missing or unreadable
// is a finding, not a reason to fall back to walking the tree. Falling back
// would be the original defect, reached by deleting one file.

require_once __DIR__ . '/helper.php';
require_once REPO . '/observers/web/container.php';

echo "the code baseline is shipped, not captured\n";

// --- it is read, not walked ------------------------------------------------

foreach (glob(REPO . '/observers/*/container.php') as $file) {
    $name = basename(dirname($file));
    $src  = file_get_contents($file);

    ok(
        preg_match('/function container_baseline\(\)[^{]*\{(?:(?!^\}).)*?code_manifest\(\)/sm', $src) !== 1,
        "$name does not build its baseline by walking its own tree"
    );
    ok(
        strpos($src, 'CONTAINER_BASELINE_FILE') !== false,
        "$name reads a baseline shipped with the image"
    );
}

// --- and what the image cannot supply is named ------------------------------
//
// `common/env.php` is the shipped example at build time by construction: the
// boot refusal on unchanged secrets exists because a real deployment must
// replace it. So it cannot appear in an image-time manifest with the value it
// will have when it runs, and it is the one path still baselined from the
// container at observer start.
//
// That is a weaker standard, and the point of naming it in a list is that the
// weaker standard is visible rather than implied. It still catches the file
// changing under a running container; what it cannot establish is what the
// file was before this observer started.

foreach (glob(REPO . '/observers/*/container.php') as $file) {
    $name = basename(dirname($file));
    $src  = file_get_contents($file);

    ok(strpos($src, 'CONTAINER_SUPPLIED_AT_DEPLOYMENT') !== false,
        "$name names what the deployment supplies rather than the image");
}

// And each list names only paths that observer's own image actually holds.
//
// The list is a fact about one container, and it was written as one literal
// copied into all four. `super` ships only `observers/super/`, so naming
// `/pr2/common/env.php` there stated something untrue of its image: a path in
// no manifest it takes, held to a weaker standard it never reaches. Nothing
// was weaker for it, because the loop skips what the baseline does not hold --
// but it is the shape this project keeps finding, one fact stated in several
// places and true in only some of them.
//
// Asked of the constants rather than the text, so the check is about what each
// observer holds rather than how it spells it.
require_once REPO . '/observers/multi/container.php';
require_once REPO . '/observers/policy/container.php';
require_once REPO . '/observers/super/container.php';

$outside = array();
foreach (array('web', 'multi', 'policy', 'super') as $name) {
    $supplied = constant('pr2obs\\' . $name . '\\CONTAINER_SUPPLIED_AT_DEPLOYMENT');
    $paths    = constant('pr2obs\\' . $name . '\\CONTAINER_CODE_PATHS');

    foreach ($supplied as $path) {
        $inside = false;
        foreach ($paths as $root) {
            if ($path === $root || strpos($path, rtrim($root, '/') . '/') === 0) {
                $inside = true;
                break;
            }
        }
        if (!$inside) {
            $outside[] = "$name declares $path, which is in none of its code paths";
        }
    }
}
is_same($outside, array(), 'every deployment-supplied path is one its own image holds');

// --- a baseline it cannot read is a finding --------------------------------
//
// Behavioural: the container check is handed nothing and must fail rather than
// pass, because "I could not establish what this container should be" is not
// a reason to say it is unchanged.

function t97_reader()
{
    return new \pr2obs\web\Reader(
        sys_get_temp_dir(), 'web',
        array(
            'window' => 4, 'cadence_seconds' => 6, 'stale_slack_cycles' => 1,
            'staging_stale_after_seconds' => 60, 'max_heartbeat_bytes' => 8192,
            'max_fault_bytes' => 8192, 'max_halt_bytes' => 8192,
            'heartbeat_max_age_seconds' => 30,
            'now' => gmdate('Y-m-d\TH:i:s\Z'),
        ),
        array(), array()
    );
}

$R = t97_reader();
\pr2obs\web\check_container($R, null, false);

$checks = array();
foreach ($R->findings as $f) {
    $checks[] = $f['check'];
}
ok(in_array('code-unchanged', $checks, true),
    'a baseline that cannot be read fails code-unchanged rather than passing');

// --- every image produces one, and leaves it unwritable --------------------

$images = array(
    'http_server'    => 'web',
    'multi_server'   => 'multi',
    'policy_server'  => 'policy',
    'super_observer' => 'super',
);

foreach ($images as $image => $observer) {
    $df = file_get_contents(REPO . "/docker/$image.dockerfile");

    ok(strpos($df, 'container-baseline') !== false,
        "$image.dockerfile produces a baseline at build time");
    ok(strpos($df, "observers/$observer/container.php") !== false,
        "and produces it with the observer that will read it");
    ok(preg_match('/chmod\s+0?444\s+\S*container-baseline/', $df) === 1,
        "and leaves it read-only, so the work cannot rewrite it");

    // It has to be the last thing that touches the tree, or it describes a
    // tree that is not the one the image ships.
    $at = strpos($df, 'container-baseline');
    $rest = substr($df, $at);
    ok(preg_match('/^(RUN|COPY|ADD)\s/m', $rest) !== 1,
        "and nothing changes the tree after it is taken in $image.dockerfile");
}

t_done();
