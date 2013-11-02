<?php
	//$dataDir = isset($_ENV['OPENSHIFT_DATA_DIR']) ? $_ENV['OPENSHIFT_DATA_DIR'] : (isset($_SERVER['OPENSHIFT_DATA_DIR']) ? $_SERVER['OPENSHIFT_DATA_DIR'] : ($_ENV["DOCUMENT_ROOT"]."/data/"));
	$dataDir = "${_SERVER['DOCUMENT_ROOT']}/data/" ;
	
	$fromPi = (isset($_REQUEST['list']) && count($_REQUEST)==1)
		|| (isset($_SERVER["HTTP_USER_AGENT"]) && $_SERVER["HTTP_USER_AGENT"]=="CDPF")
		|| isset($_REQUEST["CDPF"]);
	$title = "Connected Digital Photo Frame";
    $list = isset($_REQUEST['list']) ? $_REQUEST['list'] : '1';

    $thumbWidth = 1440/4;
    $thumbHeight = 900/4;

    $count = 1;
    $selected = 1;

    while($count<100 && is_dir($dataDir.($count+1))) {
        $count++;
    }

    if (isset($_REQUEST["count"])) {
        echo $count;
        exit;
    }

    file_put_contents($dataDir.'count.txt',''.$count);

	require_once 'twig/lib/Twig/Autoloader.php';
	Twig_Autoloader::register();
	$twig = new Twig_Environment(new Twig_Loader_Filesystem('.'));
	
	if (!$fromPi) {
		require("auth.php");
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
	
	if (isset($_REQUEST['submit']) && isset($_FILES["the-file"])) {
	
		$file = $_FILES["the-file"];
		$message = "Missing or illegal file name!";
		
		if (isset($file["name"]) && isImageName($file["name"])) {
		
			$name = preg_replace('/^[.]|[^\w.]/i','-', $file['name']);

            $album = isset($_REQUEST['the-album']) ? $_REQUEST['the-album'] : '1';

            if (preg_match('/^[0-9]+$/',$album)>0) {
                if (!is_dir($dataDir.$album)) {
                    mkdir($dataDir.$album);
                }
                $result = move_uploaded_file($file["tmp_name"],$dataDir.$album .'/'.$name);
                $message = $result ? "The photo $name has been uploaded" :
                    "Error receiving the file: $name";
            }

		}
		header('Location: /');
		exit;
	}

	$images = array();
    foreach(range(1,$count) as $i) {
        $images[$i] = @array_filter(@scandir($dataDir.$i.'/'),'isImageName');
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
	
	echo $twig->render($fromPi ? 'list.twig' : 'index.twig', array(
		'title' => $title,
		'message' => $message,
		'images' => $images,
		'ui' => !$fromPi,
        'albumCount' => $count,
        'albumSelected' => $selected,
        'list' => $list,
        'thumbWidth' => $thumbWidth,
        'thumbHeight' => $thumbHeight
	));

?>
