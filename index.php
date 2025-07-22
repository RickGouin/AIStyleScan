<?php
/**
 * AI Document Style Scanner
 * This is the main file all functions and UI.
 * 
 * @package    AIStyleScan
 * @author     Rick Gouin <rick@rickgouin.com>
 * @copyright  2025 Rick Gouin
 * @license    MIT License
 * @version    1.0.0
 * @since      2025-07-21
 */

// Check for pdftotext availability
$pdftotextAvailable = !empty(shell_exec("command -v pdftotext"));

//Get the list of excess words
require('excess_words.php');

// Configuration
// These values indicate when to flag the values in the UI
$thresholds = [
    'excessRatio' => ['red' => 0.02, 'yellow' => 0.015],
    'typeTokenRatio' => ['red' => 0.4, 'yellow' => 0.45],
    'lexicalRichness' => ['red' => 10.0, 'yellow' => 12.0],
    'avgSentenceLength' => ['red' => 22, 'yellow' => 18],
    'verbRatio' => ['red' => 0.15, 'yellow' => 0.10],
    'adjRatio' => ['red' => 0.10, 'yellow' => 0.06],
    'trigramRepeatRatio' => ['red' => 0.025, 'yellow' => 0.015],
    'pronounRatio' => ['red' => 0.005, 'yellow' => 0.01]
];
//These values provide a weight for each test, which is used in the overall AI likelihood score
$weights = [
    'excessRatio'         => 0.26, // AI often inflates with excess terminology
    'typeTokenRatio'      => 0.14, // Good uniqueness indicator
    'lexicalRichness'     => 0.14, // Penalizes flat AI vocab
    'avgSentenceLength'   => 0.06, 
    'verbRatio'           => 0.08, 
    'adjRatio'            => 0.08, 
    'trigramRepeatRatio'  => 0.14, //Good pattern marker
    'pronounRatio'        => 0.10  
];

function extractTextFromPDF($filePath, &$error = null) {
    if (!file_exists($filePath)) {
        $error = "PDF file not found.";
        return '';
    }
    $cmd = "pdftotext " . escapeshellarg($filePath) . " -";
    $output = shell_exec($cmd);
    if ($output === null || trim($output) === '') {
        $error = "Failed to extract text from PDF. The file may be scanned or contain no text.";
        return '';
    }
    return $output;
}

function riskScore($value, $threshold, $isLowerBetter = false) {
    $min = $threshold['yellow'];
    $max = $threshold['red'];
    if ($isLowerBetter) {
        if ($value <= $min) return 0;
        if ($value >= $max) return 1;
        return ($value - $min) / ($max - $min);
    } else {
        if ($value >= $max) return 1;
        if ($value <= $min) return 0;
        return ($value - $min) / ($max - $min);
    }
}

function analyzeText($text, $excessWords, $thresholds) {
    global $weights;
    $results = [];
    $words = str_word_count(strtolower($text), 1);
    $wordCount = count($words);
    $results['wordCount'] = $wordCount;
    $matches = array_intersect($words, $excessWords);
    $results['excessRatio'] = $wordCount > 0 ? count($matches) / $wordCount : 0;
    $results['matchedWords'] = $matches;
    $uniqueWords = array_unique($words);
    $results['typeTokenRatio'] = $wordCount > 0 ? count($uniqueWords) / $wordCount : 0;
    $results['lexicalRichness'] = $wordCount > 0 ? count($uniqueWords) / log($wordCount) : 0;
    $sentences = preg_split('/[.!?]/', $text, -1, PREG_SPLIT_NO_EMPTY);
    $results['avgSentenceLength'] = count($sentences) > 0 ? $wordCount / count($sentences) : 0;
    $verbSuffixes = ['ing', 'ed', 'es', 's'];
    $adjSuffixes = ['ive', 'ous', 'able', 'al', 'ful', 'less', 'ic'];
    $verbCount = 0;
    $adjCount = 0;
    foreach ($words as $word) {
        foreach ($verbSuffixes as $suf) {
            if (strlen($word) > 3 && substr($word, -strlen($suf)) === $suf) {
                $verbCount++;
                break;
            }
        }
        foreach ($adjSuffixes as $suf) {
            if (strlen($word) > 3 && substr($word, -strlen($suf)) === $suf) {
                $adjCount++;
                break;
            }
        }
    }
    $results['verbRatio'] = $wordCount > 0 ? $verbCount / $wordCount : 0;
    $results['adjRatio'] = $wordCount > 0 ? $adjCount / $wordCount : 0;

    $trigramCounts = [];
    $totalTrigrams = max(0, $wordCount - 2);
    for ($i = 0; $i < $totalTrigrams; $i++) {
        $tri = $words[$i] . ' ' . $words[$i + 1] . ' ' . $words[$i + 2];
        $trigramCounts[$tri] = ($trigramCounts[$tri] ?? 0) + 1;
    }
    $repeatTotal = array_sum(array_filter($trigramCounts, fn($c) => $c > 1));
    $results['trigramRepeatRatio'] = $totalTrigrams > 0 ? $repeatTotal / $totalTrigrams : 0;

    $pronouns = ['i', 'we', 'you', 'my', 'our', 'your', 'me', 'us'];
    $pronounCount = count(array_intersect($words, $pronouns));
    $results['pronounRatio'] = $wordCount > 0 ? $pronounCount / $wordCount : 0;

    $score =
        $weights['excessRatio'] * riskScore($results['excessRatio'], $thresholds['excessRatio']) +
        $weights['typeTokenRatio'] * riskScore($results['typeTokenRatio'], $thresholds['typeTokenRatio'], true) +
        $weights['lexicalRichness'] * riskScore($results['lexicalRichness'], $thresholds['lexicalRichness'], true) +
        $weights['avgSentenceLength'] * riskScore($results['avgSentenceLength'], $thresholds['avgSentenceLength']) +
        $weights['verbRatio'] * riskScore($results['verbRatio'], $thresholds['verbRatio']) +
        $weights['adjRatio'] * riskScore($results['adjRatio'], $thresholds['adjRatio']) +
        $weights['trigramRepeatRatio'] * riskScore($results['trigramRepeatRatio'], $thresholds['trigramRepeatRatio']) +
        $weights['pronounRatio'] * riskScore($results['pronounRatio'], $thresholds['pronounRatio'], true);

    $results['aiScore'] = round($score * 100, 1);
    return $results;
}

$analysis = null;
$textSource = '';
$errorMessage = null;
$shortTextWarning = false;
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    if ($pdftotextAvailable && !empty($_FILES['pdfFile']['tmp_name'])) {
        $text = extractTextFromPDF($_FILES['pdfFile']['tmp_name'], $errorMessage);
        $textSource = 'PDF';
    } elseif (!empty($_POST["inputText"])) {
        $text = $_POST["inputText"];
        $textSource = 'Text Box';
    }
    if (!empty($text)) {
        $wordCount = str_word_count($text);
        if ($wordCount < 100) $shortTextWarning = true;
        $analysis = analyzeText($text, $excessWords, $thresholds);
    }
}

function flag($value, $thresholds, $labels, $isPercentage = false, $isLowerBetter = false) {
    if ($isPercentage) $value *= 100;
    if ($isLowerBetter) {
        if ($value <= $thresholds['red']) return "<span class='flag red'>{$labels['red']}</span>";
        elseif ($value <= $thresholds['yellow']) return "<span class='flag yellow'>{$labels['yellow']}</span>";
        else return "<span class='flag green'>{$labels['green']}</span>";
    } else {
        if ($value >= $thresholds['red']) return "<span class='flag red'>{$labels['red']}</span>";
        elseif ($value >= $thresholds['yellow']) return "<span class='flag yellow'>{$labels['yellow']}</span>";
        else return "<span class='flag green'>{$labels['green']}</span>";
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>AI Writing Style Detector</title>
    <style>
        body { font-family: Arial, sans-serif; background: #f8f9fa; margin: 0; padding: 2em; }
        h2, h3, h4 { color: #343a40; }
        form, .results, .help { background: #fff; padding: 20px; margin-bottom: 20px; border-radius: 10px; box-shadow: 0 2px 5px rgba(0,0,0,0.1); }
        textarea, input[type="file"] { width: 100%; padding: 10px; margin-top: 5px; }
        input[type="submit"] { padding: 10px 20px; background: #007bff; color: #fff; border: none; cursor: pointer; margin-top: 10px; }
        input[type="submit"]:hover { background: #0056b3; }
        ul { list-style: none; padding-left: 0; }
        li { margin-bottom: 10px; }
        .flag { font-weight: bold; padding: 3px 6px; border-radius: 5px; }
        .flag.red { color: #fff; background: #dc3545; }
        .flag.yellow { color: #212529; background: #ffc107; }
        .flag.green { color: #fff; background: #28a745; }
        .help p { margin: 6px 0; font-size: 14px; }
        .help strong { color: #007bff; }
    </style>
</head>
<body>

<h2>🧠 LLM Writing Style Detector</h2>

<form method="post" enctype="multipart/form-data">
    <?php if (!$pdftotextAvailable): ?>
        <p class="flag yellow">⚠️ <strong>PDF upload is disabled:</strong> <code>pdftotext</code> is not installed on this server.</p>
    <?php endif; ?>
    <label>📋 Paste Text:</label><br>
    <textarea name="inputText" rows="12" placeholder="Paste your abstract or text here..."></textarea><br><br>
    <?php if ($pdftotextAvailable): ?>
        <label>📎 OR Upload a PDF:</label>
        <input type="file" name="pdfFile" accept=".pdf"><br><br>
    <?php endif; ?>
    <input type="submit" value="Analyze Text">
</form>

<?php if ($errorMessage): ?>
    <p class="flag red"><strong>Error:</strong> <?= htmlspecialchars($errorMessage) ?></p>
<?php endif; ?>

<?php if ($shortTextWarning): ?>
    <p class="flag yellow">⚠️ Input contains fewer than 100 words. Results may be less reliable.</p>
<?php endif; ?>

<?php if ($analysis): ?>
    
<div class="results">
    <h4>📊 AI Likelihood Score: <strong><?= $analysis['aiScore'] ?>%</strong></h4>
    <p>
        <?php
        if ($analysis['aiScore'] >= 70) echo "<span class='flag red'>🚩 Strong AI signature detected.</span>";
        elseif ($analysis['aiScore'] >= 40) echo "<span class='flag yellow'>⚠️ Possibly AI-generated.</span>";
        elseif ($analysis['aiScore'] >= 30) echo "<span class='flag yellow'>🟠 Mixed signs – possibly human with edits.</span>";
        else echo "<span class='flag green'>✅ Likely human-written content.</span>";
        ?>
    </p>
    <h3>🔍 Analysis Results (from <?= $textSource ?>):</h3>
    <ul>
        <li><strong>Excess Word Ratio:</strong> <?= round($analysis['excessRatio'] * 100, 2) ?>%
            <?= flag($analysis['excessRatio'], $thresholds['excessRatio'], [
                'red' => 'High – AI-Likely',
                'yellow' => 'Moderate – Possibly AI',
                'green' => 'Low – Human-Likely'
            ], true) ?> — Words: <?= implode(', ', $analysis['matchedWords']) ?>
        </li>
        <li><strong>Type-Token Ratio:</strong> <?= round($analysis['typeTokenRatio'], 3) ?>
            <?= flag($analysis['typeTokenRatio'], $thresholds['typeTokenRatio'], [
                'red' => 'Low – AI-Likely',
                'yellow' => 'Borderline',
                'green' => 'Rich – Human-Like'
            ], false, true) ?>
        </li>
        <li><strong>Lexical Richness:</strong> <?= round($analysis['lexicalRichness'], 2) ?>
            <?= flag($analysis['lexicalRichness'], $thresholds['lexicalRichness'], [
                'red' => 'Low – Repetitive',
                'yellow' => 'Moderate',
                'green' => 'High – Varied'
            ], false, true) ?>
        </li>
        <li><strong>Avg. Sentence Length:</strong> <?= round($analysis['avgSentenceLength'], 2) ?> words
            <?= flag($analysis['avgSentenceLength'], $thresholds['avgSentenceLength'], [
                'red' => 'Long – AI-Likely',
                'yellow' => 'Slightly Long',
                'green' => 'Normal'
            ]) ?>
        </li>
        <li><strong>Verb Ratio:</strong> <?= round($analysis['verbRatio'] * 100, 2) ?>%
            <?= flag($analysis['verbRatio'], $thresholds['verbRatio'], [
                'red' => 'High – AI-Likely',
                'yellow' => 'Elevated',
                'green' => 'Typical'
            ], true) ?>
        </li>
        <li><strong>Adjective Ratio:</strong> <?= round($analysis['adjRatio'] * 100, 2) ?>%
            <?= flag($analysis['adjRatio'], $thresholds['adjRatio'], [
                'red' => 'High – AI-Likely',
                'yellow' => 'Moderate',
                'green' => 'Normal'
            ], true) ?>
        </li>
        <li><strong>Repeated Trigram Ratio:</strong> <?= round($analysis['trigramRepeatRatio'] * 100, 2) ?>%
            <?= flag($analysis['trigramRepeatRatio'], $thresholds['trigramRepeatRatio'], [
                'red' => 'Repetitive',
                'yellow' => 'Some Repeats',
                'green' => 'Low'
            ], true) ?>
        </li>
        <li><strong>Pronoun Ratio:</strong> <?= round($analysis['pronounRatio'] * 100, 3) ?>%
            <?= flag($analysis['pronounRatio'], $thresholds['pronounRatio'], [
                'red' => 'Very Low – AI-Likely',
                'yellow' => 'Low',
                'green' => 'Human-Like'
            ], true, true) ?>
        </li>
    </ul>
</div>
<?php endif; ?>

<div class="help">
    <h3>ℹ️ About the Tests</h3>
    <p><strong>Excess Word Ratio</strong>: Flags overused, formalized vocabulary common in LLMs.</p>
    <p><strong>Type-Token Ratio</strong>: Measures diversity of word use. Lower = more repetition.</p>
    <p><strong>Lexical Richness</strong>: Ratio of unique words adjusted by length (entropy-like).</p>
    <p><strong>Average Sentence Length</strong>: AI often favors longer, clause-heavy sentences.</p>
    <p><strong>Verb / Adjective Ratio</strong>: AI tends to overuse action and descriptive words.</p>
    <p><strong>Trigram Repetition</strong>: Repeating the same 3-word patterns is typical in AI writing.</p>
    <p><strong>Pronoun Ratio</strong>: Human text often includes "I", "we", etc. AI text less so.</p>
    <p><br/></p>
    <p><strong>Inspired by</strong>: <a href="https://www.science.org/doi/10.1126/sciadv.adt3813">https://www.science.org/doi/10.1126/sciadv.adt3813</a></p>
</div>
<div>
<p>AI Writing Style Detector by <A href="http://www.rickgouin.com">Rick Gouin</a></p>
</div>
</body>
</html>
