<?php
require_once __DIR__.'/includes/functions.php';
if(logged_in()) audit((int)current_user()['id'],'USER_LOGOUT','users',(string)current_user()['id'],'User logged out');
$_SESSION=[]; if(ini_get('session.use_cookies')) { $p=session_get_cookie_params(); setcookie(session_name(),'',time()-42000,$p['path'],$p['domain'],$p['secure'],$p['httponly']); } session_destroy(); header('Location: '.url('login.php')); exit;
