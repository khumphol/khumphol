<?php
$_SESSION = [];
session_regenerate_id(true);
redirect(u());
