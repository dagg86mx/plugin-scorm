<?php
/**
 * Gets readable text (and, when the format allows it, simple HTML) out of the files people send:
 * PDF, Word (.docx and old .doc), OpenDocument, RTF, HTML and plain text. Needs no WordPress.
 *
 * @package Cletbot
 */

namespace Cletbot;

defined( 'ABSPATH' ) || defined( 'CLETBOT_TESTING' ) || exit;

class Extractor {

	const MAX_CHARS = 200000;

	/**
	 * Which kind of file is this? Uses the extension first because email clients often send
	 * application/octet-stream.
	 *
	 * @return string pdf|docx|doc|odt|rtf|html|text|image|other
	 */
	public static function kind( $filename, $mime = '' ) {
		$ext  = strtolower( pathinfo( (string) $filename, PATHINFO_EXTENSION ) );
		$mime = strtolower( (string) $mime );
		$by_ext = array(
			'pdf'  => 'pdf',
			'docx' => 'docx',
			'docm' => 'docx',
			'doc'  => 'doc',
			'odt'  => 'odt',
			'rtf'  => 'rtf',
			'html' => 'html',
			'htm'  => 'html',
			'txt'  => 'text',
			'md'   => 'text',
			'csv'  => 'text',
			'jpg'  => 'image',
			'jpeg' => 'image',
			'png'  => 'image',
			'gif'  => 'image',
			'webp' => 'image',
		);
		if ( isset( $by_ext[ $ext ] ) ) {
			return $by_ext[ $ext ];
		}
		if ( 'application/pdf' === $mime ) {
			return 'pdf';
		}
		if ( false !== strpos( $mime, 'wordprocessingml' ) ) {
			return 'docx';
		}
		if ( 'application/msword' === $mime ) {
			return 'doc';
		}
		if ( false !== strpos( $mime, 'opendocument.text' ) ) {
			return 'odt';
		}
		if ( false !== strpos( $mime, 'rtf' ) ) {
			return 'rtf';
		}
		if ( 'text/html' === $mime ) {
			return 'html';
		}
		if ( 0 === strpos( $mime, 'text/' ) ) {
			return 'text';
		}
		if ( in_array( $mime, array( 'image/jpeg', 'image/png', 'image/gif', 'image/webp' ), true ) ) {
			return 'image';
		}
		return 'other';
	}

	/**
	 * @return array { kind, text, html } — html is '' when the format has no structure we can keep.
	 */
	public static function extract( $path, $filename = '', $mime = '' ) {
		$kind = self::kind( '' !== $filename ? $filename : $path, $mime );
		$out  = array(
			'kind' => $kind,
			'text' => '',
			'html' => '',
		);
		if ( ! is_readable( $path ) ) {
			return $out;
		}
		switch ( $kind ) {
			case 'docx':
				$out = array_merge( $out, self::docx( $path ) );
				break;
			case 'odt':
				$out = array_merge( $out, self::odt( $path ) );
				break;
			case 'pdf':
				$out['text'] = self::pdf( $path );
				break;
			case 'doc':
				$out['text'] = self::doc( $path );
				break;
			case 'rtf':
				$out['text'] = self::rtf( file_get_contents( $path ) );
				break;
			case 'html':
				$out['text'] = self::html_to_text( file_get_contents( $path ) );
				break;
			case 'text':
				$out['text'] = self::to_utf8( file_get_contents( $path ) );
				break;
		}
		$out['text'] = self::clean( $out['text'] );
		return $out;
	}

	public static function clean( $text ) {
		$text = self::to_utf8( (string) $text );
		$text = str_replace( array( "\r\n", "\r", "\xC2\xA0", "\f" ), array( "\n", "\n", ' ', "\n\n" ), $text );
		$text = preg_replace( '/[^\P{C}\n\t]/u', '', $text );
		$text = preg_replace( '/[ \t]+/', ' ', $text );
		$text = preg_replace( '/ *\n */', "\n", $text );
		$text = preg_replace( "/\n{3,}/", "\n\n", $text );
		$text = trim( $text );
		if ( strlen( $text ) > self::MAX_CHARS ) {
			$text = substr( $text, 0, self::MAX_CHARS );
			$text = function_exists( 'mb_convert_encoding' ) ? mb_convert_encoding( $text, 'UTF-8', 'UTF-8' ) : $text;
		}
		return $text;
	}

	public static function to_utf8( $text ) {
		$text = (string) $text;
		if ( 0 === strpos( $text, "\xEF\xBB\xBF" ) ) {
			$text = substr( $text, 3 );
		} elseif ( 0 === strpos( $text, "\xFF\xFE" ) || 0 === strpos( $text, "\xFE\xFF" ) ) {
			$text = function_exists( 'mb_convert_encoding' ) ? mb_convert_encoding( substr( $text, 2 ), 'UTF-8', 0 === strpos( $text, "\xFF\xFE" ) ? 'UTF-16LE' : 'UTF-16BE' ) : $text;
		}
		if ( function_exists( 'mb_check_encoding' ) && ! mb_check_encoding( $text, 'UTF-8' ) ) {
			$text = mb_convert_encoding( $text, 'UTF-8', 'Windows-1252' );
		}
		return $text;
	}

	/* ------------------------------------------------------------------ Word (.docx) */

	public static function docx( $path ) {
		$xml = self::zip_entry( $path, 'word/document.xml' );
		if ( '' === $xml ) {
			return array( 'text' => '', 'html' => '' );
		}
		$doc = self::load_xml( $xml );
		if ( ! $doc ) {
			return array( 'text' => self::html_to_text( $xml ), 'html' => '' );
		}
		$w     = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
		$xpath = new \DOMXPath( $doc );
		$xpath->registerNamespace( 'w', $w );

		$text   = array();
		$html   = '';
		$list   = '';
		$body   = $xpath->query( '//w:body' )->item( 0 );
		$blocks = $body ? $xpath->query( './w:p | ./w:tbl | ./w:sdt/w:sdtContent/w:p', $body ) : array();
		foreach ( $blocks as $block ) {
			if ( 'tbl' === $block->localName ) {
				if ( $list ) {
					$html .= "</{$list}>\n";
					$list  = '';
				}
				$html .= self::docx_table( $xpath, $block, $text );
				continue;
			}
			$runs = self::docx_runs( $xpath, $block );
			$line = trim( $runs['text'] );
			if ( '' === $line ) {
				continue;
			}
			$style   = $xpath->evaluate( 'string(./w:pPr/w:pStyle/@w:val)', $block );
			$is_list = $xpath->query( './w:pPr/w:numPr', $block )->length > 0 || preg_match( '/^(List|Lista)/i', $style );
			$level   = self::heading_level( $style );
			$text[]  = $line;

			if ( $is_list ) {
				$tag = preg_match( '/Number|Numer/i', $style ) ? 'ol' : 'ul';
				if ( $list !== $tag ) {
					$html .= $list ? "</{$list}>\n" : '';
					$html .= "<{$tag}>\n";
					$list  = $tag;
				}
				$html .= '<li>' . $runs['html'] . "</li>\n";
				continue;
			}
			if ( $list ) {
				$html .= "</{$list}>\n";
				$list  = '';
			}
			if ( $level ) {
				$html .= "<h{$level}>" . self::esc( $line ) . "</h{$level}>\n";
			} elseif ( preg_match( '/Quote|Cita/i', $style ) ) {
				$html .= '<blockquote><p>' . $runs['html'] . "</p></blockquote>\n";
			} else {
				$html .= '<p>' . $runs['html'] . "</p>\n";
			}
		}
		if ( $list ) {
			$html .= "</{$list}>\n";
		}
		return array(
			'text' => implode( "\n\n", $text ),
			'html' => trim( $html ),
		);
	}

	/** Title → h1 is reserved for the post title, so the document's own headings start at h2. */
	private static function heading_level( $style ) {
		if ( preg_match( '/^(Title|T[ií]tulo)$/iu', $style ) ) {
			return 2;
		}
		if ( preg_match( '/(Heading|Ttulo|T[ií]tulo|berschrift)\s*([1-6])/iu', $style, $m ) ) {
			return min( 6, (int) $m[2] + 1 );
		}
		return 0;
	}

	private static function docx_runs( \DOMXPath $xpath, \DOMNode $p ) {
		$text = '';
		$html = '';
		foreach ( $xpath->query( './/w:r | .//w:hyperlink', $p ) as $node ) {
			if ( 'hyperlink' === $node->localName ) {
				continue; // Its runs are visited on their own.
			}
			$t = '';
			foreach ( $node->childNodes as $child ) {
				if ( 't' === $child->localName ) {
					$t .= $child->textContent;
				} elseif ( 'tab' === $child->localName ) {
					$t .= ' ';
				} elseif ( 'br' === $child->localName || 'cr' === $child->localName ) {
					$t .= "\n";
				}
			}
			if ( '' === $t ) {
				continue;
			}
			$text .= $t;
			$piece = nl2br( self::esc( $t ), false );
			if ( $xpath->query( './w:rPr/w:b[not(@w:val="0") and not(@w:val="false")]', $node )->length ) {
				$piece = '<strong>' . $piece . '</strong>';
			}
			if ( $xpath->query( './w:rPr/w:i[not(@w:val="0") and not(@w:val="false")]', $node )->length ) {
				$piece = '<em>' . $piece . '</em>';
			}
			$html .= $piece;
		}
		$html = str_replace( array( '</strong><strong>', '</em><em>' ), '', $html );
		return array(
			'text' => $text,
			'html' => trim( $html ),
		);
	}

	private static function docx_table( \DOMXPath $xpath, \DOMNode $tbl, array &$text ) {
		$html = "<table>\n";
		foreach ( $xpath->query( './w:tr', $tbl ) as $tr ) {
			$cells = array();
			$html .= '<tr>';
			foreach ( $xpath->query( './w:tc', $tr ) as $tc ) {
				$parts = array();
				foreach ( $xpath->query( './/w:p', $tc ) as $p ) {
					$r = self::docx_runs( $xpath, $p );
					if ( '' !== trim( $r['text'] ) ) {
						$parts[] = $r['html'];
						$cells[] = trim( $r['text'] );
					}
				}
				$html .= '<td>' . implode( '<br>', $parts ) . '</td>';
			}
			$html  .= "</tr>\n";
			$text[] = implode( ' | ', $cells );
		}
		return $html . "</table>\n";
	}

	/* ------------------------------------------------------------------ OpenDocument (.odt) */

	public static function odt( $path ) {
		$xml = self::zip_entry( $path, 'content.xml' );
		$doc = '' !== $xml ? self::load_xml( $xml ) : null;
		if ( ! $doc ) {
			return array( 'text' => '', 'html' => '' );
		}
		$xpath = new \DOMXPath( $doc );
		$xpath->registerNamespace( 'text', 'urn:oasis:names:tc:opendocument:xmlns:text:1.0' );
		$text = array();
		$html = '';
		foreach ( $xpath->query( '//text:h | //text:p[not(ancestor::text:list-item)] | //text:list' ) as $node ) {
			if ( 'list' === $node->localName ) {
				if ( $node->parentNode && 'list-item' === $node->parentNode->localName ) {
					continue;
				}
				$html .= "<ul>\n";
				foreach ( $xpath->query( './/text:list-item', $node ) as $li ) {
					$t = trim( $li->textContent );
					if ( '' !== $t ) {
						$text[] = '- ' . $t;
						$html  .= '<li>' . self::esc( $t ) . "</li>\n";
					}
				}
				$html .= "</ul>\n";
				continue;
			}
			$t = trim( $node->textContent );
			if ( '' === $t ) {
				continue;
			}
			$text[] = $t;
			if ( 'h' === $node->localName ) {
				$level = min( 6, max( 2, (int) $node->getAttributeNS( 'urn:oasis:names:tc:opendocument:xmlns:text:1.0', 'outline-level' ) + 1 ) );
				$html .= "<h{$level}>" . self::esc( $t ) . "</h{$level}>\n";
			} else {
				$html .= '<p>' . self::esc( $t ) . "</p>\n";
			}
		}
		return array(
			'text' => implode( "\n\n", $text ),
			'html' => trim( $html ),
		);
	}

	/* ------------------------------------------------------------------ PDF */

	/**
	 * Uses pdftotext (poppler) when the server has it, otherwise a small built-in reader that
	 * handles the common case: text drawn with Tj/TJ in Flate-compressed content streams.
	 * When AI is configured the PDF itself is also sent to Claude, which reads it far better.
	 */
	public static function pdf( $path ) {
		$cmd = self::binary( 'pdftotext' );
		if ( $cmd ) {
			$out = self::run( escapeshellcmd( $cmd ) . ' -layout -enc UTF-8 ' . escapeshellarg( $path ) . ' -' );
			if ( '' !== trim( $out ) ) {
				return $out;
			}
		}
		return self::pdf_builtin( file_get_contents( $path ) );
	}

	public static function pdf_builtin( $data ) {
		$data = (string) $data;
		$text = '';
		if ( ! preg_match_all( '/<<(.*?)>>\s*stream\r?\n(.*?)\r?\nendstream/s', $data, $streams, PREG_SET_ORDER ) ) {
			return '';
		}
		foreach ( $streams as $s ) {
			$dict    = $s[1];
			$content = $s[2];
			if ( preg_match( '#/Subtype\s*/(Image|XML)|/Type\s*/(XRef|ObjStm|Metadata)#', $dict ) ) {
				continue;
			}
			if ( false !== strpos( $dict, '/FlateDecode' ) ) {
				$decoded = @gzuncompress( $content ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
				if ( false === $decoded ) {
					$decoded = @gzinflate( substr( $content, 2 ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
				}
				if ( false === $decoded ) {
					continue;
				}
				$content = $decoded;
			} elseif ( preg_match( '#/Filter#', $dict ) ) {
				continue;
			}
			if ( false === strpos( $content, 'BT' ) ) {
				continue;
			}
			$text .= self::pdf_text_ops( $content ) . "\n\n";
		}
		return $text;
	}

	private static function pdf_text_ops( $content ) {
		$out = '';
		if ( ! preg_match_all( '/BT(.*?)ET/s', $content, $blocks ) ) {
			return '';
		}
		foreach ( $blocks[1] as $block ) {
			preg_match_all( '/(\[(?:\\\\.|[^\]])*\]\s*TJ|\((?:\\\\.|[^\\\\)])*\)\s*(?:Tj|\'|")|T\*|-?[\d.]+\s+-?[\d.]+\s+(?:Td|TD)|(?:-?[\d.]+\s+){6}Tm)/s', $block, $ops );
			$line = '';
			foreach ( $ops[0] as $op ) {
				if ( preg_match( '/(Td|TD|Tm|T\*)$/', $op ) ) {
					if ( preg_match( '/(-?[\d.]+)\s+(-?[\d.]+)\s+T[dD]$/', $op, $td ) && 0.0 === (float) $td[2] ) {
						$line .= ' ';
						continue;
					}
					$out .= rtrim( $line ) . "\n";
					$line = '';
					continue;
				}
				if ( '[' === $op[0] ) {
					preg_match_all( '/\((?:\\\\.|[^\\\\)])*\)|-?[\d.]+/s', substr( $op, 1, strrpos( $op, ']' ) - 1 ), $parts );
					foreach ( $parts[0] as $part ) {
						if ( '(' === $part[0] ) {
							$line .= self::pdf_string( substr( $part, 1, -1 ) );
						} elseif ( (float) $part < -200 ) {
							$line .= ' ';
						}
					}
				} else {
					$line .= self::pdf_string( substr( $op, 1, strrpos( $op, ')' ) - 1 ) );
				}
			}
			$out .= rtrim( $line ) . "\n";
		}
		return $out;
	}

	private static function pdf_string( $s ) {
		$s = preg_replace_callback(
			'/\\\\([nrtbf()\\\\]|[0-7]{1,3}|\r?\n)/',
			function ( $m ) {
				$map = array( 'n' => "\n", 'r' => '', 't' => "\t", 'b' => '', 'f' => '', '(' => '(', ')' => ')', '\\' => '\\' );
				if ( isset( $map[ $m[1] ] ) ) {
					return $map[ $m[1] ];
				}
				if ( ctype_digit( $m[1] ) ) {
					return chr( octdec( $m[1] ) & 0xFF );
				}
				return '';
			},
			$s
		);
		if ( 0 === strpos( $s, "\xFE\xFF" ) && function_exists( 'mb_convert_encoding' ) ) {
			return mb_convert_encoding( substr( $s, 2 ), 'UTF-8', 'UTF-16BE' );
		}
		return function_exists( 'mb_convert_encoding' ) && ! mb_check_encoding( $s, 'UTF-8' ) ? mb_convert_encoding( $s, 'UTF-8', 'Windows-1252' ) : $s;
	}

	/* ------------------------------------------------------------------ Word 97-2003 (.doc) */

	public static function doc( $path ) {
		foreach ( array( 'antiword' => ' -m UTF-8.txt ', 'catdoc' => ' -d utf-8 ' ) as $bin => $args ) {
			$cmd = self::binary( $bin );
			if ( $cmd ) {
				$out = self::run( escapeshellcmd( $cmd ) . $args . escapeshellarg( $path ) );
				if ( '' !== trim( $out ) ) {
					return $out;
				}
			}
		}
		// Fallback: Word 97 stores the text as UTF-16LE (or 8-bit) runs; keep the long readable ones.
		$data = file_get_contents( $path );
		$text = '';
		if ( function_exists( 'mb_convert_encoding' ) && preg_match_all( '/(?:[\x20-\x7E\xA0-\xFF\r\n\t]\x00){20,}/', $data, $m ) ) {
			foreach ( $m[0] as $run ) {
				$text .= mb_convert_encoding( $run, 'UTF-8', 'UTF-16LE' ) . "\n";
			}
		}
		if ( '' === trim( $text ) && preg_match_all( '/[\x20-\x7E\xA0-\xFF\r\n\t]{40,}/', $data, $m ) ) {
			$text = implode( "\n", $m[0] );
		}
		return str_replace( "\r", "\n", $text );
	}

	/* ------------------------------------------------------------------ RTF / HTML */

	public static function rtf( $rtf ) {
		$rtf = (string) $rtf;
		// Drop destinations we never want as text (font tables, styles, pictures…).
		$rtf = preg_replace( '/\{\\\\\*[^{}]*(?:\{[^{}]*\}[^{}]*)*\}/s', '', $rtf );
		$rtf = preg_replace( '/\{\\\\(fonttbl|colortbl|stylesheet|info|pict)(?:[^{}]|\{[^{}]*\})*\}/s', '', $rtf );
		$rtf = preg_replace_callback(
			"/\\\\'([0-9a-fA-F]{2})/",
			function ( $m ) {
				return function_exists( 'mb_convert_encoding' ) ? mb_convert_encoding( chr( hexdec( $m[1] ) ), 'UTF-8', 'Windows-1252' ) : chr( hexdec( $m[1] ) );
			},
			$rtf
		);
		$rtf = preg_replace_callback(
			'/\\\\u(-?\d+)\??/',
			function ( $m ) {
				$cp = (int) $m[1];
				$cp = $cp < 0 ? $cp + 65536 : $cp;
				return function_exists( 'mb_chr' ) ? mb_chr( $cp, 'UTF-8' ) : '';
			},
			$rtf
		);
		$rtf = preg_replace( '/\\\\(par|line)\b ?/', "\n", $rtf );
		$rtf = preg_replace( '/\\\\tab\b ?/', ' ', $rtf );
		$rtf = preg_replace( '/\\\\[a-zA-Z]+-?\d* ?/', '', $rtf );
		$rtf = str_replace( array( '\\{', '\\}', '\\\\', '{', '}' ), array( "\x01", "\x02", "\x03", '', '' ), $rtf );
		return str_replace( array( "\x01", "\x02", "\x03" ), array( '{', '}', '\\' ), $rtf );
	}

	public static function html_to_text( $html ) {
		$html = self::to_utf8( (string) $html );
		$html = preg_replace( '#<(script|style|head)\b.*?</\1>#is', '', $html );
		$html = preg_replace( '#<br\s*/?>#i', "\n", $html );
		$html = preg_replace( '#</(p|div|h[1-6]|li|tr|blockquote|table|ul|ol)>#i', "\n\n", $html );
		$html = preg_replace( '#<li\b[^>]*>#i', '- ', $html );
		$text = html_entity_decode( strip_tags( $html ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		return self::clean( $text );
	}

	/* ------------------------------------------------------------------ helpers */

	private static function zip_entry( $path, $entry ) {
		if ( ! class_exists( '\ZipArchive' ) ) {
			return '';
		}
		$zip = new \ZipArchive();
		if ( true !== $zip->open( $path ) ) {
			return '';
		}
		$data = $zip->getFromName( $entry );
		$zip->close();
		return false === $data ? '' : $data;
	}

	private static function load_xml( $xml ) {
		$doc = new \DOMDocument();
		$old = libxml_use_internal_errors( true );
		// LIBXML_NONET: never fetch external entities from a document someone sent us.
		$ok = $doc->loadXML( $xml, LIBXML_NONET | LIBXML_COMPACT | LIBXML_PARSEHUGE );
		libxml_clear_errors();
		libxml_use_internal_errors( $old );
		return $ok ? $doc : null;
	}

	private static function esc( $text ) {
		return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
	}

	private static function can_exec() {
		if ( ! function_exists( 'shell_exec' ) ) {
			return false;
		}
		$disabled = array_map( 'trim', explode( ',', (string) ini_get( 'disable_functions' ) ) );
		return ! in_array( 'shell_exec', $disabled, true );
	}

	private static function binary( $name ) {
		if ( ! self::can_exec() ) {
			return '';
		}
		foreach ( array( '/usr/bin/', '/usr/local/bin/', '/opt/homebrew/bin/' ) as $dir ) {
			if ( @is_executable( $dir . $name ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
				return $dir . $name;
			}
		}
		return '';
	}

	private static function run( $cmd ) {
		$out = shell_exec( $cmd . ' 2>/dev/null' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
		return is_string( $out ) ? $out : '';
	}
}
