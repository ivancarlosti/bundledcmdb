<?php
// logout.php

session_start();
require_once '../config.php';
require_once '../auth_keycloak.php';
session_destroy();
header('Location: index.php?action=logout');
exit();
