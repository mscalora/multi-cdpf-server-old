<?php
	$authed = false;
	$pwfile = "$dataDir.passwd";

	if (!is_file("$pwfile")) {	
		if (isset($_REQUEST['setup']) && isset($_REQUEST['secret']) && strlen($_REQUEST['secret'])>=6) {
			$HASH = crypt($_REQUEST['secret']);
			file_put_contents($pwfile,$HASH);
			header('Location: /');
			exit;
		}
		
		if ($_SERVER["REQUEST_METHOD"]!="GET") {
			header('Location: /');
			exit;		
		}
		
		echo $twig->render('auth.twig', array(
			'title' => $title . " - One Time Setup",
			'prompt' => "Enter password of at least 6 characters to secure your site.",
			'setup' => true
		));
		exit;
	}

	$UUID = isset($_ENV['OPENSHIFT_GEAR_UUID']) ? $_ENV['OPENSHIFT_GEAR_UUID'] : $_SERVER["SERVER_SIGNATURE"];
	$DATE = date("dlFmY");
	$IP = $_SERVER["REMOTE_ADDR"];
	$PWHASH = file_get_contents($pwfile);
	
	$TOKEN = sha1("$UUID.$DATE.$IP.$PWHASH");
	

	if (isset($_REQUEST['logout'])) {
		setcookie("ticket", "", time()-60*60*24, "/");
		header('Location: /');
		exit;
	} elseif (isset($_REQUEST['login']) && isset($_REQUEST['secret'])) {
		if (crypt($_REQUEST['secret'],$PWHASH)==$PWHASH) {
			setcookie("ticket", $TOKEN, time()+60*60*24, "/");
			header('Location: /');
			exit;
		}
	} elseif(isset($_COOKIE['ticket'])) {
		if ($_COOKIE['ticket']==$TOKEN) {
			$authed = true;
		}
	}

	if (!$authed) {
		if ($_SERVER["REQUEST_METHOD"]!="GET") {
			header('Location: /');
			exit;		
		}
		
		echo $twig->render('auth.twig', array(
			'title' => $title,
			'prompt' => "Login",
			'setup' => false
		));
		exit;		
	}


?>
