<?php
/**
 * Compile a .po file into a .mo file without gettext tools.
 *
 *     php bin/po2mo.php languages/oc-b2b-he_IL.po [languages/oc-b2b-he_IL.mo]
 *
 * Handles msgctxt, msgid_plural / msgstr[n], continuation lines and the usual
 * C escapes. Entries with an empty translation are skipped (WordPress falls
 * back to the source string anyway). Output follows the GNU MO format,
 * revision 0, little-endian, entries sorted by key, no hash table.
 */
if ( 'cli' !== PHP_SAPI ) {
	exit( "CLI only.\n" );
}
$in  = $argv[1] ?? '';
$out = $argv[2] ?? preg_replace( '/\.po$/', '.mo', $in );
if ( ! is_file( $in ) ) {
	fwrite( STDERR, "usage: php bin/po2mo.php file.po [file.mo]\n" );
	exit( 1 );
}

function po_unescape( $s ) {
	return preg_replace_callback(
		'/\\\\(n|t|r|"|\\\\)/',
		function ( $m ) {
			switch ( $m[1] ) {
				case 'n':
					return "\n";
				case 't':
					return "\t";
				case 'r':
					return "\r";
				case '"':
					return '"';
			}
			return '\\';
		},
		$s
	);
}

$entries = array();
$cur     = array();
$field   = null;
$flush   = function () use ( &$entries, &$cur ) {
	if ( isset( $cur['msgid'] ) ) {
		$entries[] = $cur;
	}
	$cur = array();
};
foreach ( file( $in, FILE_IGNORE_NEW_LINES ) as $line ) {
	$line = rtrim( $line, "\r" );
	if ( '' === trim( $line ) ) {
		$flush();
		$field = null;
		continue;
	}
	if ( '#' === $line[0] ) {
		continue;
	}
	if ( preg_match( '/^(msgctxt|msgid_plural|msgid|msgstr\[\d+\]|msgstr)\s+"(.*)"$/', $line, $m ) ) {
		$field         = $m[1];
		$cur[ $field ] = po_unescape( $m[2] );
		continue;
	}
	if ( preg_match( '/^"(.*)"$/', $line, $m ) && null !== $field ) {
		$cur[ $field ] .= po_unescape( $m[1] );
	}
}
$flush();

$table = array();
foreach ( $entries as $e ) {
	$key = $e['msgid'];
	if ( isset( $e['msgctxt'] ) ) {
		$key = $e['msgctxt'] . "\x04" . $key;
	}
	if ( isset( $e['msgid_plural'] ) ) {
		$key  .= "\0" . $e['msgid_plural'];
		$forms = array();
		for ( $i = 0; isset( $e[ "msgstr[$i]" ] ); $i++ ) {
			$forms[] = $e[ "msgstr[$i]" ];
		}
		$val = implode( "\0", $forms );
	} else {
		$val = isset( $e['msgstr'] ) ? $e['msgstr'] : '';
	}
	if ( '' === $val && '' !== $e['msgid'] ) {
		continue;
	}
	$table[ $key ] = $val;
}
ksort( $table, SORT_STRING );

$n       = count( $table );
$keys    = array_keys( $table );
$vals    = array_values( $table );
$ko      = 28 + 0;          // originals table right after the header (no hash table)
$to      = $ko + 8 * $n;    // translations table
$strings = '';
$offs    = $to + 8 * $n;    // where the string data starts
$otab    = '';
$ttab    = '';
foreach ( $keys as $k ) {
	$otab    .= pack( 'VV', strlen( $k ), $offs + strlen( $strings ) );
	$strings .= $k . "\0";
}
foreach ( $vals as $v ) {
	$ttab    .= pack( 'VV', strlen( $v ), $offs + strlen( $strings ) );
	$strings .= $v . "\0";
}
$mo = pack( 'VVVVVVV', 0x950412de, 0, $n, $ko, $to, 0, $offs ) . $otab . $ttab . $strings;
file_put_contents( $out, $mo );
echo "wrote $out: $n entries\n";
