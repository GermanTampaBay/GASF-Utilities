<?php
/**
 * Plugin Name: GASF MEC Importer Fixes (loader)
 * Description: Loads the GASF Utilities mu-plugin from the git working copy at /home4/germanta/gasf-muplugin (repo: github.com/GermanTampaBay/GASF-Utilities). Deploy updates with `git pull` in that directory. Replaces Code Snippets #17-#21.
 * Version:     1.0.0
 * Author:      GASF
 *
 * INSTALL: copy this file to wp-content/mu-plugins/gasf-mec-importer.php
 * (mu-plugins autoload only top-level files, so this thin loader lives there
 * and requires the git working copy that sits outside the web root).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$gasf_mec_src = '/home4/germanta/gasf-muplugin/gasf-mec-importer.php';
if ( is_readable( $gasf_mec_src ) ) {
	require_once $gasf_mec_src;
}
unset( $gasf_mec_src );
