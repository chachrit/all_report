</div>
    <?php if (isset($conn) && $conn): sqlsrv_close($conn); endif; ?>
    <script src="assets/section-manager.js?v=<?php echo (int) (@filemtime(__DIR__ . '/../assets/section-manager.js') ?: 0); ?>" defer></script>
</body>
</html>