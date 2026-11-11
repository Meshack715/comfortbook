<?php
require_once __DIR__ . '/_guard.php';
require_once __DIR__ . '/_layout.php';
require_once __DIR__ . '/../includes/explore_ui.php';
student_header('Explore the hostel', $STUDENT, ['full' => true]);
explore_render('student');
student_footer(['full' => true]);
