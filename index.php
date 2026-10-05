<?php
// หน้าบ้าน (public) — index.php?p=...
require __DIR__.'/core/bootstrap.php';
run_area('public', [
    'home'              => 'public/home.php',
    'courses'           => 'public/home.php',
    'course'            => 'public/course.php',
    'checkout'          => 'public/checkout.php',
    'pay-mock'          => 'public/pay-mock.php',
    'pay-return'        => 'public/pay-return.php',
    'learn'             => 'public/learn.php',
    'my-learning'       => 'public/my-learning.php',
    'login'             => 'public/login.php',
    'register'          => 'public/register.php',
    'logout'            => 'public/logout.php',
    'become-instructor' => 'public/become-instructor.php',
    'cart'              => 'public/cart.php',
    'receipt'           => 'public/receipt.php',
    'certificate'       => 'public/certificate.php',
], 'home');
