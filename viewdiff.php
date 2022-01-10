<!DOCTYPE HTML PUBLIC "-//W3C//DTD HTML 4.01 Transitional//EN" "http://www.w3.org/TR/html4/loose.dtd">
<html>

<head>
	<title>Text comparator </title>
	<meta http-equiv="Content-Type" content="text/html; charset=utf-8">
	<link rel="stylesheet" href="style.css">
</head>

<body>
	<div>
		<?php
		$colorTo = $_POST['toColor'];
		$colorFrom = $_POST['fromColor'];

		echo '<style>
		ins {
			background: ' . $colorFrom . ';
			text-decoration: none
		}
		
		del {
			background: ' . $colorTo . ';
			text-decoration: none
		}
		</style>';

		// http://www.php.net/manual/en/function.get-magic-quotes-gpc.php#82524
		function stripslashes_deep(&$value)
		{
			$value = is_array($value) ? array_map('stripslashes_deep', $value) : stripslashes($value);
			return $value;
		}
		if ((function_exists("get_magic_quotes_gpc") && get_magic_quotes_gpc()) || (ini_get('magic_quotes_sybase') && strtolower(ini_get('magic_quotes_sybase')) != "off")) {
			stripslashes_deep($_GET);
			stripslashes_deep($_POST);
		}

		include 'finediff.php';

		$cache_lo_water_mark = 900;
		$cache_hi_water_mark = 1100;
		$compressed_serialized_filename_extension = '.store.gz';

		$granularity = 2;
		$from_text = '';
		$to_text = '';
		$diff_opcodes = '';
		$diff_opcodes_len = 0;
		$data_key = '';

		$start_time = gettimeofday(true);

		// restore from cache
		if (isset($_GET['data'])) {
			if (ctype_alnum($_GET['data'])) {
				$filename = "{$_GET['data']}{$compressed_serialized_filename_extension}";
				$compressed_serialized_data = @file_get_contents("./cache/{$filename}");
				if ($compressed_serialized_data !== false) {
					@touch("./cache/{$filename}");
					$data_from_serialization = unserialize(gzuncompress($compressed_serialized_data));
					$granularity = $data_from_serialization['granularity'];
					$from_text = $data_from_serialization['from_text'];
					$diff_opcodes = $data_from_serialization['diff_opcodes'];
					$diff_opcodes_len = strlen($diff_opcodes);
					$to_text = FineDiff::renderToTextFromOpcodes($from_text, $diff_opcodes);
					$data_key = $data_from_serialization['data_key'];
				} else {
					echo '<p style="font-size:smaller">The page you are looking for has expired.</p>', "\n";
				}
			}
			$exec_time = gettimeofday(true) - $start_time;
		}
		// new diff
		else {
			if (isset($_POST['granularity']) && ctype_digit($_POST['granularity'])) {
				$granularity = max(min(intval($_POST['granularity']), 3), 0);
			}
			if (!empty($_POST['from']) || !empty($_POST['to'])) {
				if (!empty($_POST['from'])) {
					$from_text = $_POST['from'];
				}
				if (!empty($_POST['to'])) {
					$to_text = $_POST['to'];
				}
			}
			// limit input
			$from_text = substr($from_text, 0, 1024 * 100);
			$to_text = substr($to_text, 0, 1024 * 100);

			// ensure input is suitable for diff
			$from_text = mb_convert_encoding($from_text, 'HTML-ENTITIES', 'UTF-8');
			$to_text = mb_convert_encoding($to_text, 'HTML-ENTITIES', 'UTF-8');

			$granularityStacks = array(
				FineDiff::$paragraphGranularity,
				FineDiff::$sentenceGranularity,
				FineDiff::$wordGranularity,
				FineDiff::$characterGranularity
			);
			
			$diff_opcodes = FineDiff::getDiffOpcodes($from_text, $to_text, $granularityStacks[$granularity]);
			$diff_opcodes_len = strlen($diff_opcodes);
			$exec_time = gettimeofday(true) - $start_time;
			if ($diff_opcodes_len) {
				$data_key = sha1(serialize(array('granularity' => $granularity, 'from_text' => $from_text, 'diff_opcodes' => $diff_opcodes)));
				$filename = "{$data_key}{$compressed_serialized_filename_extension}";
				if (!file_exists("./cache/{$filename}")) {
					// purge cache if too many files
					if (!(time() % 100)) {
						$files = glob("./cache/*{$compressed_serialized_filename_extension}");
						$num_files = $files ? count($files) : 0;
						if ($num_files > $cache_hi_water_mark) {
							$sorted_files = array();
							foreach ($files as $file) {
								$sorted_files[strval(@filemtime("./cache/{$file}")) . $file] = $file;
							}
							ksort($sorted_files);
							foreach ($sorted_files as $file) {
								@unlink("./cache/{$file}");
								$num_files -= 1;
								if ($num_files < $cache_lo_water_mark) {
									break;
								}
							}
						}
					}
					$temp;
					if (isset($_POST['swap']))
					{
						$temp = $from_text;
						$from_text = $to_text;
						$to_text = $temp;
					}
					
					// save diff in cache
					$data_to_serialize = array(
						'granularity' => $granularity,
						'from_text' => $from_text,
						'diff_opcodes' => $diff_opcodes,
						'data_key' => $data_key,
					);
					$serialized_data = serialize($data_to_serialize);
					@file_put_contents("./cache/{$filename}", gzcompress($serialized_data));
					@chmod("./cache/{$filename}", 0666);
					
				}
			}
		}
		if (isset($_POST['clearAll']))
		{
			$from_text = "";
			$to_text = "";
		}
		$rendered_diff = FineDiff::renderDiffToHTMLFromOpcodes($from_text, $diff_opcodes);
		$from_len = strlen($from_text);
		$to_len = strlen($to_text);
		
		?>
		<h1>Text comparator online</h1>
		<div class="panecontainer" style="width:99%">
			<p>Diff <span style="color:gray">(diff: <?php printf('%.3f', $exec_time); ?> seconds, diff len: <?php echo $diff_opcodes_len; ?> chars)</span>&emsp;/&emsp;Show <input type="radio" name="htmldiffshow" onclick="setHTMLDiffVisibility('deletions');">Deletions only&ensp;<input type="radio" name="htmldiffshow" checked="checked" onclick="setHTMLDiffVisibility();">All&ensp;<input type="radio" name="htmldiffshow" onclick="setHTMLDiffVisibility('insertions');">Insertions only</p>
			<div>
				<div id="htmldiff" class="pane" style="white-space:pre-wrap"><?php
																				echo $rendered_diff; ?></div>
			</div>
		</div>
		<form action="viewdiff.php" method="post">
			<p style="margin:1em 0 0.5em 0">Write some text</p>
			<div class="panecontainer" style="display:inline-block;width:49.5%">
				<p>First text input</p>
				<div>
					<textarea name="from" class="pane"><?php echo htmlentities($from_text, ENT_QUOTES, 'UTF-8'); ?></textarea>
				</div>
			</div>
			<div class="panecontainer" style="display:inline-block;width:49.5%">
				<p>Second text input</p>
				<div><textarea name="to" class="pane"><?php echo htmlentities($to_text, ENT_QUOTES, 'UTF-8'); ?></textarea></div>
			</div>
			<p id="params">Granularity:<input name="granularity" type="radio" value="0">&thinsp;Paragraph/lines&ensp;
			<input name="granularity" type="radio" value="1">&thinsp;Sentence&ensp;<input name="granularity" type="radio" value="2" checked="checked">
			&thinsp;Word&ensp;<input name="granularity" type="radio" value="3">&thinsp;Character&emsp;
			<input type="submit" value="View diff">&emsp;<input type="submit" value="Clear All" name="clearAll">&thinsp;
			<input type="submit" value="Swap" name="swap"></p>
		</p>
			
			First text color : <input name="fromColor" type="color" id="body" name="body" value="#ff0000">
			<p>
			Second text color : <input name="toColor" type="color" id="body" name="body" value="#00ff00">
		</form>

</body>

</html>