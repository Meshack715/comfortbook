# Student-side update (v2)
- New: includes/booking_state.php (one source of truth for booking windows), student/book.php rewritten around it, late-booking fee gate,
  dean "what students see" panel + late-booking settings (user/phase_windows.php), AI aware of window state.
- Fixed: /students/... links 404 (real folder is /student/; old /students/ now forwards), .htaccess ErrorDocument paths,
  fee credited to the wrong semester when paid by staff, staff session hijacking the AI on student pages.
- No database changes beyond the previous update.
