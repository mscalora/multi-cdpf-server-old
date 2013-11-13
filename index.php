<?php
    require_once('lib/Mobile_Detect.php');

	$dataDir = trim(is_file('.datadir_path') ?
		file_get_contents('.datadir_path') :
		"${_SERVER['DOCUMENT_ROOT']}/data/");

	$fromPi = (isset($_REQUEST['list']) && count($_REQUEST)==1)
		|| (isset($_SERVER["HTTP_USER_AGENT"]) && $_SERVER["HTTP_USER_AGENT"]=="CDPF")
		|| isset($_REQUEST["CDPF"]);
    $list = isset($_REQUEST['list']) ? $_REQUEST['list'] : '1';

    $count = 1;
    $selected = 1;

    while($count<100 && is_dir($dataDir.($count+1))) {
        $count++;
    }

    $ipmapPath = "${dataDir}ipmap.txt";

    $configDir = "${dataDir}";
    $configFile = "${configDir}cdpf.ini";
    $configDefaults = array(
        "title" => "Connected Digital Photo Frame",
        "thumbWidth" => 1440/4,
        "thumbHeight" => 900/4,
        "albumNames" => array()
    );
    if (is_file($configFile)) {
        $config =  array_merge($configDefaults,parse_ini_file($configFile));
    }
    if (!isset($config) || $config===false) {
        $config = $configDefaults;
    }

    $exiftool = isset($config['exiftoolPath']) ?
        $config['exiftoolPath'] :
            trim(is_file('.exiftool_path') ?
            file_get_contents('.exiftool_path') :
            `which exiftool || which ~/localbin/exiftool || cat ~/.exiftool_path 2>/dev/null || find /usr/local -type file -name "exiftool" | head -n 1`);

    $twigData = array();
    $twigKeys = array(
        "title", "albumNames",
        "thumbWidth", "thumbHeight",
        "googleAnalyticsID",
        "authImage", "authPrompt", "authLabel", "authHashes", "authCaseInsensitive",
        "customCSS", "customJS"
    );
    foreach ($twigKeys as $key) {
        if (isset($config[$key])) $twigData[$key] = $config[$key];
    }


//    $twigData['title'] = $config['title'];
//    $twigData['thumbWidth'] = $config['thumbWidth'];
//    $twigData['thumbHeight'] = $config['thumbHeight'];
//    isset($config['googleAnalyticsID']) ? $twigData['googleAnalyticsID'] = $config['googleAnalyticsID'] : 0;

	if (isset($_REQUEST["debug"])) {
		echo "<div class='division'>";
		echo "<style> html { background: pink; } b { display: inline-block; margin: 0; padding: 0; text-weight: bold; min-width: 125px; font-family: monospace; } .division div { font-family: monospace; }</style>\n";
		echo "<h2>CDPF Configuration</h2>\n";
		echo "<div><b>exiftool</b>$exiftool</div>\n";
		echo "<div><b>dataDir</b>$dataDir</div>\n";
		echo "<div><b>fromPi</b>$fromPi</div>\n";
		echo "<div><b>album count</b>$count</div>\n";
        echo $_REQUEST["debug"]!=="" ? ("<div><b>config</b>${_REQUEST["config"]}</div>\n") : "";
        echo $_REQUEST["debug"]!=="" ? ("<div><b>secret</b>".crypt($_REQUEST["config"])."</div>\n") : "";
		echo "</div>";
		echo "<div class='division'><h2>GLOBALS</h2>\n";
		echo "<div style='white-space: pre; font-family: monospace; font-size: 120%;'>";
		var_dump($GLOBALS);
		echo "</div>";

		exit;
    }
    
    if (isset($_REQUEST["count"])) {
        echo $count;
        if (isset($_REQUEST["ip"])) {
            $ip = $_REQUEST["ip"];
            if (preg_match('/^[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+$/',$ip)==1) {
                $map = @unserialize(@file_get_contents($ipmapPath));
                $map = is_array($map) ? $map : array();
                $map['latest'] = $ip;
                if (isset($_SERVER["REMOTE_ADDR"])) {
                    $map[$_SERVER["REMOTE_ADDR"]] = $ip;
                }
                file_put_contents($ipmapPath,serialize($map));
            }
        }
        exit;
    }

    file_put_contents($dataDir.'count.txt',''.$count);

	require_once 'twig/lib/Twig/Autoloader.php';
	Twig_Autoloader::register();
	$twig = new Twig_Environment(new Twig_Loader_Filesystem('.'));

    $function = new Twig_SimpleFunction('filetimestamp', function ($file) {
        return "".filemtime($file);
    }, array('is_safe' => array('html')));
    $twig->addFunction($function);

    $detect = new Mobile_Detect;
    $htmlClasses =
        ($detect->isMobile() ? "ismobile" : "isnotmobile") .
        ($detect->isTablet() ? " istablet" : " isnottablet") .
        ($detect->isiPhone() ? " isiphone" : "") .
        ($detect->isiPad() ? " isipad" : "") .
        ($detect->isiOS() ? " isios" : "") .
        ($detect->isAndroidOS() ? " isandroid" : "") .
        ($detect->isSafari() ? " issafari" : "") .
        "";
    $twigData['htmlClasses'] = $htmlClasses;


	if (!$fromPi) {
		require("auth.php");
	}

    if (isset($_REQUEST["save-config"]) || isset($_REQUEST["config"])) {
        require("config.php");
        exit;
    }

    // test for image file names
	function isImageName($name) {
        global $dataDir;
        // abs path case
        if (strpos($name,$dataDir)===0) {
            return preg_match('/^[0-9]+\/[^.\/][^\/]*\.(jpe?g|png|gif)$/i',substr($name,strlen($dataDir)))>0;
        }
        // filename only
        return preg_match('/^[^.\/][^\/]*\.(jpe?g|png|gif)$/i',$name)>0;
	}

	// for debugging
	if (isset($_REQUEST['phpinfo'])) {
		phpinfo();
		exit;
	}
	
	// handle serving image files 
	if (isset($_REQUEST['f'])) {

        $file = $dataDir . $_REQUEST['f'];

        if (isImageName($file) && is_file($file)) {

			// set content type if a legal image extention
			if (preg_match('/\.gif/i',$file)) {
				header('Content-Type: image/gif');
			} else if (preg_match('/\.jpe?g/i',$file)) {
				header('Content-Type: image/jpeg');
			} else if (preg_match('/\.png/i',$file)) {
				header('Content-Type: image/png');
			} else {
				header ("HTTP/1.0 404 Not Found");
				exit;
			}

			// Checking if the client is validating his cache and if it is current.
			if (isset($_SERVER['HTTP_IF_MODIFIED_SINCE']) && (strtotime($_SERVER['HTTP_IF_MODIFIED_SINCE']) == filemtime($file))) {
				// Client's cache IS current, so we just respond '304 Not Modified'.
				header('Last-Modified: '.gmdate('D, d M Y H:i:s', filemtime($file)).' GMT', true, 304);
				exit;
			}
			
			// insert Last-Modified header for wget's -N option
			header('Last-Modified: '.gmdate('D, d M Y H:i:s', filemtime($file)).' GMT', true, 200);
			header('Content-Length: '.filesize($file));
			// for HEAD requests (which WGET claims to use) we don't send the content
			if ($_SERVER['REQUEST_METHOD']=='HEAD') {
				exit;
			}
			
			// tell clients to cache for 5 years
			$seconds_to_cache = 60*60*24*365*5;
			$ts = gmdate("D, d M Y H:i:s", time() + $seconds_to_cache) . " GMT";
			header("Expires: $ts");
			header("Pragma: cache");
			header("Cache-Control: max-age=$seconds_to_cache");
			header('Pragma: public');
			
			// send image to client
			readfile($file);
			exit;
			
		}
		
		// error
		header ("HTTP/1.0 404 Not Found");
		exit;
	}

    if (isset($_REQUEST['save'])) {
        $caption = isset($_REQUEST['caption']) ? $_REQUEST['caption'] : "";
        $image = isset($_REQUEST['image']) ? $_REQUEST['image'] : "";

        $cmd = "$exiftool -UserComment=" . escapeshellarg($caption) . " " . escapeshellarg('data/' . $image) . " 2>&1";

        $out = exec($cmd);

        error_log("Modify Caption Operation for " . $image);
        error_log("New caption: <" . $caption . ">");
        error_log("Command: " . $cmd);
        error_log("Output: " . $out);

        $exif = exif_read_data('data/' . $image, 0, false);
        $caption = isset($exif['COMPUTED']['UserComment']) ? $exif['COMPUTED']['UserComment'] : "";

        print $caption;

        exit;
    }

    if (isset($_REQUEST['rotate'])) {

        $right = (isset($_REQUEST['dir']) && $_REQUEST['dir']=='left') ? false : true;
        $name = isset($_REQUEST['name']) ? $_REQUEST['name'] : false;

        $dataPath = "data/$name";
        $validPath = preg_match('/^\d+\/[^\/]+\.(jpeg|jpg|gif|png)$/i',$name)==1 && is_file($dataPath);

        if ($validPath && $name!==false && is_file($dataPath)) {
            $undoFile = "undo/${name}_ROT" . ($right ? "R" : "L") . date('Ymd\THisT',filemtime($dataPath));
            if (!is_dir(dirname($undoFile))) {
                mkdir(dirname($undoFile),0777, true);
            }
            rename($dataPath, $undoFile);
            $cmd = "jpegtran -v -v -v -rotate " . ($right?90:270) . " -outfile " . escapeshellarg($dataPath) . " " . escapeshellarg($undoFile) . " 2>&1";
            $out = exec($cmd);
            if (is_file($dataPath)) touch($dataPath);
            echo filemtime($dataPath);
            if ($debug) {
                echo "";
                echo $cmd;
                echo "";
                echo $out;
            }
        } else {
            header("HTTP/1.0 403 Forbidden");
            exit;
        }
        exit;
    }

	$message = "";

    function getImageData($path,$name) {
        $exif = exif_read_data($path, 0, false);
        return array(
            "caption" => (isset($exif['COMPUTED']['UserComment']) ? $exif['COMPUTED']['UserComment'] : ""),
            "name" => $name,
            "size" => getimagesize($path)
        );
    }


    if (isset($_REQUEST['upload-submit']) && isset($_FILES["the-file"])) {
	
		$file = $_FILES["the-file"];
		$message = "Missing or illegal file name!";
		
		if (isset($file["name"]) && isImageName($file["name"])) {
		
			$name = preg_replace('/^[.]|[^\w.]/i','-', $file['name']);

            $album = isset($_REQUEST['the-album']) ? $_REQUEST['the-album'] : '1';

            if (preg_match('/^[0-9]+$/',$album)>0) {
                if (!is_dir($dataDir.$album)) {
                    mkdir($dataDir.$album);
                }
                $i = 1;
                $suffix = "";
                do {
                    $newName = preg_replace('/^(.*)\.([^.]*)$/',"$1$suffix.$2",$name);
                    $path = "$dataDir$album/$newName";
                    $i++;
                    $suffix = "_$i";
                } while(is_file($path));

                $name = $newName;
                $result = move_uploaded_file($file["tmp_name"],$path);

                if ($result) {
                    $message = "The photo $name has been uploaded";
                    echo $twig->render('image.twig', array_merge($twigData,array(
                        'ajaxMessage' => $message,
                        'item' => getImageData($path,$name),
                        'i' => $album,
                    )));
                    exit;
                } else {
                    $message = "Error receiving the file: $name";
                }
            }
		}
        echo "<div class='message error'>".htmlentities($message)."</div>";
		exit;
	}

	$images = array();
    foreach(range(1,$count) as $i) {
        $names = @array_filter(@scandir($dataDir.$i.'/'),'isImageName');
        $album = array();
        foreach($names as $name) {
            $path = $dataDir.$i.'/'.$name;
            $album[] = getImageData($path,$name);
        }
        $images[$i] = $album;
    }

	if (isset($_REQUEST['delete-image'])) {
		$name = $_REQUEST['delete-image'];
        $dataPath = "data/$name";
        $validPath = preg_match('/^\d+\/[^\/]+\.(jpeg|jpg|gif|png)$/i',$name)==1 && is_file($dataPath);

		if ($validPath) {
            $undoFile = "undo/${name}_DEL" . date('Ymd\THisT',filemtime($dataPath));
            $thumbFile = "thumb/${name}";
            if (!is_dir(dirname($undoFile))) {
                mkdir(dirname($undoFile),0777, true);
            }
            rename($dataPath, $undoFile);
            @unlink($thumbFile);
			header('Location: /');
			exit;
		}
	}

    $ip = false;
    $map = @unserialize(@file_get_contents($ipmapPath));
    if (is_array($map)) {
        $remote = isset($_SERVER["REMOTE_ADDR"]) ? $_SERVER["REMOTE_ADDR"] : 'latest';
        $ip = isset($map[$remote]) ? $map[$remote] : $map['latest'];
    }

	echo $twig->render($fromPi ? 'list.twig' : 'index.twig', array_merge($twigData,array(
        'message' => $message,
		'images' => $images,
		'ui' => !$fromPi,
        'albumCount' => $count,
        'albumSelected' => $selected,
        'list' => $list,
        'ip' => $ip,
	)));

?>
