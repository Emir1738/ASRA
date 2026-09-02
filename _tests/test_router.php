<?php
/**
 * Test yönlendiricisi.
 *
 * Bildirim/push sistemi kaldırıldığı için bu yönlendiricinin işi artık
 * yalnızca dosya sunmak: `return false` dediğinde yerleşik sunucu isteği
 * docroot'tan (asra-site-fix) kendisi karşılar.
 *
 * Dosya olarak tutulmasının nedeni geriye dönük uyumluluktur — test
 * betikleri sunucuyu "php -S ... _tests/test_router.php" biçiminde başlatır.
 */
return false;
