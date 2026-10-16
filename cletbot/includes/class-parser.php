<?php
/**
 * Pure text helpers (no WordPress needed): reads what people send by email or WhatsApp
 * and tells apart a new submission from a reply such as "SI", "NO" or "CAMBIOS: …".
 *
 * @package Cletbot
 */

namespace Cletbot;

defined( 'ABSPATH' ) || defined( 'CLETBOT_TESTING' ) || exit;

class Parser {

	/** Characters used in approval codes (no 0/O, 1/I/L to avoid confusion when typing them back). */
	const CODE_ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
	const CODE_LENGTH   = 5;

	const APPROVE_WORDS = array( 'si', 'sip', 'ok', 'okay', 'va', 'dale', 'publicar', 'publica', 'publicalo', 'publicala', 'aprobar', 'apruebo', 'aprobado', 'autorizo', 'autorizado', 'adelante', 'yes', 'publish', 'approve' );
	const REJECT_WORDS  = array( 'no', 'cancelar', 'cancela', 'cancelado', 'descartar', 'descarta', 'rechazar', 'rechazo', 'rechazado', 'borrar', 'borra', 'eliminar', 'reject', 'cancel' );
	const REVISE_WORDS  = array( 'cambios', 'cambio', 'cambiar', 'cambia', 'corregir', 'corrige', 'correccion', 'correcciones', 'editar', 'edita', 'ajustar', 'ajusta', 'ajustes', 'modificar', 'modifica', 'changes', 'edit' );
	const HELP_WORDS    = array( 'ayuda', 'help', 'comandos', 'instrucciones', '?' );
	const STATUS_WORDS  = array( 'pendientes', 'estado', 'status', 'lista' );

	/**
	 * Lowercase, strip accents and collapse whitespace.
	 */
	public static function normalize( $text ) {
		$text = (string) $text;
		$map  = array(
			'á' => 'a', 'à' => 'a', 'ä' => 'a', 'â' => 'a', 'ã' => 'a', 'Á' => 'a', 'À' => 'a', 'Ä' => 'a', 'Â' => 'a', 'Ã' => 'a',
			'é' => 'e', 'è' => 'e', 'ë' => 'e', 'ê' => 'e', 'É' => 'e', 'È' => 'e', 'Ë' => 'e', 'Ê' => 'e',
			'í' => 'i', 'ì' => 'i', 'ï' => 'i', 'î' => 'i', 'Í' => 'i', 'Ì' => 'i', 'Ï' => 'i', 'Î' => 'i',
			'ó' => 'o', 'ò' => 'o', 'ö' => 'o', 'ô' => 'o', 'õ' => 'o', 'Ó' => 'o', 'Ò' => 'o', 'Ö' => 'o', 'Ô' => 'o', 'Õ' => 'o',
			'ú' => 'u', 'ù' => 'u', 'ü' => 'u', 'û' => 'u', 'Ú' => 'u', 'Ù' => 'u', 'Ü' => 'u', 'Û' => 'u',
			'ñ' => 'n', 'Ñ' => 'n', 'ç' => 'c', 'Ç' => 'c',
		);
		$text = strtr( $text, $map );
		$text = strtolower( $text );
		return trim( preg_replace( '/\s+/', ' ', $text ) );
	}

	public static function generate_code() {
		$code = '';
		$max  = strlen( self::CODE_ALPHABET ) - 1;
		for ( $i = 0; $i < self::CODE_LENGTH; $i++ ) {
			$code .= self::CODE_ALPHABET[ random_int( 0, $max ) ];
		}
		return $code;
	}

	/**
	 * Is this message a command about a pending post? Returns null for a new submission.
	 *
	 * @return array|null { action: approve|reject|revise|category|help|status, code: string, arg: string }
	 */
	public static function parse_command( $text ) {
		$text = trim( (string) $text );
		if ( '' === $text ) {
			return null;
		}
		$code_re = '(?:#?([A-Za-z0-9]{' . self::CODE_LENGTH . '}))';
		$norm    = self::normalize( $text );

		// Short answers: "SI", "sí, publícalo", "NO K7M2Q", "ok #K7M2Q".
		$short = preg_replace( '/^[\s¡¿]+|[\s.,!?¡¿👍✅🙌]+$/u', '', $norm );
		$short = preg_replace( '/^(si|no)\s*,\s*/', '$1 ', $short );
		if ( strlen( $short ) <= 40 && preg_match( '/^([a-z?]+)(?:\s+([a-z]+))?(?:\s+' . $code_re . ')?$/u', $short, $m ) ) {
			$word   = $m[1];
			$second = isset( $m[2] ) ? $m[2] : '';
			$code   = isset( $m[3] ) ? strtoupper( $m[3] ) : '';
			// "si publicalo" / "no gracias": the second word is filler, unless it is really the code.
			if ( '' !== $second && '' === $code && self::looks_like_code( $second ) ) {
				$code   = strtoupper( $second );
				$second = '';
			}
			$fillers = array_merge( self::APPROVE_WORDS, self::REJECT_WORDS, array( 'gracias', 'porfa', 'favor', 'please', 'thanks', 'ya', 'todo', 'bien', 'claro', 'listo', 'perfecto', 'excelente', 'adelante' ) );
			if ( '' === $second || in_array( $second, $fillers, true ) ) {
				foreach ( array( 'approve' => self::APPROVE_WORDS, 'reject' => self::REJECT_WORDS, 'help' => self::HELP_WORDS, 'status' => self::STATUS_WORDS ) as $action => $words ) {
					if ( in_array( $word, $words, true ) ) {
						return array( 'action' => $action, 'code' => $code, 'arg' => '' );
					}
				}
			}
		}

		// "CAMBIOS: el título debe ser…" / "corregir K7M2Q - quita el último párrafo".
		if ( preg_match( '/^\s*(\S+)(?:\s+' . $code_re . ')?\s*[:\-–]\s*(.+)$/su', $text, $m ) ) {
			$word = self::normalize( $m[1] );
			$code = isset( $m[2] ) ? strtoupper( $m[2] ) : '';
			$arg  = trim( $m[3] );
			if ( in_array( $word, self::REVISE_WORDS, true ) && '' !== $arg ) {
				return array( 'action' => 'revise', 'code' => $code, 'arg' => $arg );
			}
			// "categoría K7M2Q: Eventos" (with code, so it can't be confused with a new submission).
			if ( '' !== $code && in_array( $word, array( 'categoria', 'category', 'cat' ), true ) ) {
				return array( 'action' => 'category', 'code' => $code, 'arg' => self::first_line( $arg ) );
			}
		}
		return null;
	}

	/** Codes never contain O, I, L, 0 or 1, so "claro" or "bueno" can't be mistaken for one. */
	private static function looks_like_code( $word ) {
		return strlen( $word ) === self::CODE_LENGTH && strspn( strtoupper( $word ), self::CODE_ALPHABET ) === self::CODE_LENGTH;
	}

	private static function first_line( $text ) {
		$lines = preg_split( '/\R/', trim( $text ) );
		return trim( $lines[0] );
	}

	/**
	 * Pulls the directives people put at the top of a message out of the content.
	 *
	 *     Categoría: Noticias
	 *     Título: Inauguramos el nuevo laboratorio
	 *     Etiquetas: ciencia, campus
	 *     Notas: que sea breve y con tono formal
	 *
	 * Also "[Noticias] Asunto" in an email subject (pass the subject as $subject).
	 *
	 * @return array { category, title, tags[], instructions, body }
	 */
	public static function parse_submission( $text, $subject = '' ) {
		$out = array(
			'category'     => '',
			'title'        => '',
			'tags'         => array(),
			'instructions' => '',
			'body'         => '',
		);

		$subject = trim( preg_replace( '/^\s*((re|fw|fwd|rv|reenviar)\s*:\s*)+/i', '', (string) $subject ) );
		if ( preg_match( '/^\[([^\]]+)\]\s*(.*)$/u', $subject, $m ) && ! preg_match( '/^cletbot/i', $m[1] ) ) {
			$out['category'] = trim( $m[1] );
			$subject         = trim( $m[2] );
		}
		if ( '' !== $subject ) {
			$out['title'] = $subject;
		}

		$keys = array(
			'category'     => array( 'categoria', 'category', 'cat', 'seccion' ),
			'title'        => array( 'titulo', 'title', 'encabezado' ),
			'tags'         => array( 'etiquetas', 'etiqueta', 'tags', 'tag' ),
			'instructions' => array( 'notas', 'nota', 'instrucciones', 'indicaciones', 'instruccion', 'instructions', 'note', 'notes' ),
		);

		$body  = array();
		$lines = preg_split( '/\R/', str_replace( "\xC2\xA0", ' ', (string) $text ) );
		foreach ( $lines as $line ) {
			$matched = false;
			if ( preg_match( '/^\s*[*_]*\s*([\p{L} ]{2,20}?)\s*[*_]*\s*:\s*(.*)$/u', $line, $m ) ) {
				$label = self::normalize( $m[1] );
				$value = trim( $m[2], " \t*_" );
				foreach ( $keys as $field => $labels ) {
					if ( in_array( $label, $labels, true ) && '' !== $value ) {
						if ( 'tags' === $field ) {
							$out['tags'] = array_values( array_filter( array_map( 'trim', preg_split( '/[,;#]/', $value ) ) ) );
						} elseif ( 'instructions' === $field ) {
							$out['instructions'] = trim( $out['instructions'] . "\n" . $value );
						} else {
							$out[ $field ] = $value;
						}
						$matched = true;
						break;
					}
				}
			}
			if ( ! $matched ) {
				$body[] = $line;
			}
		}
		$out['body'] = trim( implode( "\n", $body ) );
		return $out;
	}

	/**
	 * Keeps only the new part of an email reply (drops the quoted original and signatures).
	 */
	public static function strip_quoted_reply( $text ) {
		$lines = preg_split( '/\R/', (string) $text );
		$keep  = array();
		foreach ( $lines as $line ) {
			$t = trim( $line );
			if ( '-- ' === rtrim( $line, "\r" ) || '--' === $t
				|| preg_match( '/^-{2,}\s*(original message|mensaje original|forwarded message|mensaje reenviado)/i', $t )
				|| preg_match( '/^(on|el)\s.+(wrote|escribi[oó]):\s*$/iu', $t )
				|| preg_match( '/^(from|de):\s.+@/i', $t )
				|| preg_match( '/^(sent from my|enviado desde mi)/i', $t )
				|| ( 0 === strpos( $t, '>' ) && ! empty( $keep ) ) ) {
				break;
			}
			if ( 0 === strpos( $t, '>' ) ) {
				continue;
			}
			$keep[] = $line;
		}
		return trim( implode( "\n", $keep ) );
	}

	/**
	 * "Re: [CLETBOT #K7M2Q] Título" → "K7M2Q".
	 */
	public static function code_from_subject( $subject ) {
		if ( preg_match( '/\[CLETBOT\s*#([A-Za-z0-9]{' . self::CODE_LENGTH . '})\]/i', (string) $subject, $m ) ) {
			return strtoupper( $m[1] );
		}
		return '';
	}

	/**
	 * Splits the "authorized senders" setting (one per line, commas also accepted) into emails and phones.
	 *
	 * @return array { emails: string[], phones: string[] }
	 */
	public static function parse_sender_list( $raw ) {
		$out = array(
			'emails' => array(),
			'phones' => array(),
		);
		foreach ( preg_split( '/[\r\n,;]+/', (string) $raw ) as $entry ) {
			$entry = trim( preg_replace( '/#.*$/', '', $entry ) );
			if ( '' === $entry ) {
				continue;
			}
			if ( false !== strpos( $entry, '@' ) ) {
				$out['emails'][] = strtolower( $entry );
			} else {
				$phone = self::normalize_phone( $entry );
				if ( strlen( $phone ) >= 8 ) {
					$out['phones'][] = $phone;
				}
			}
		}
		$out['emails'] = array_values( array_unique( $out['emails'] ) );
		$out['phones'] = array_values( array_unique( $out['phones'] ) );
		return $out;
	}

	/**
	 * Digits only. Mexican mobiles arrive from WhatsApp as 521 + 10 digits; we store them as 52 + 10 digits.
	 */
	public static function normalize_phone( $phone ) {
		$digits = preg_replace( '/\D+/', '', (string) $phone );
		if ( 13 === strlen( $digits ) && 0 === strpos( $digits, '521' ) ) {
			$digits = '52' . substr( $digits, 3 );
		}
		return $digits;
	}

	/**
	 * Does "Nombre <correo@x.com>" (or a bare address / phone) appear in the list?
	 * An entry "@dominio.com" authorizes the whole domain.
	 */
	public static function sender_in_list( $sender, array $list ) {
		$sender = trim( (string) $sender );
		if ( preg_match( '/<([^>]+)>/', $sender, $m ) ) {
			$sender = $m[1];
		}
		if ( false !== strpos( $sender, '@' ) ) {
			$email = strtolower( trim( $sender ) );
			foreach ( $list['emails'] as $allowed ) {
				if ( $allowed === $email || ( 0 === strpos( $allowed, '@' ) && substr( $email, -strlen( $allowed ) ) === $allowed ) ) {
					return true;
				}
			}
			return false;
		}
		$phone = self::normalize_phone( $sender );
		return '' !== $phone && in_array( $phone, $list['phones'], true );
	}

	public static function email_address( $from ) {
		$from = trim( (string) $from );
		if ( preg_match( '/<([^>]+)>/', $from, $m ) ) {
			$from = $m[1];
		}
		return strtolower( trim( $from, " \"'" ) );
	}

	/**
	 * Did the receiving mail server verify that the email really comes from its domain?
	 * Looks at the Authentication-Results header added by Gmail, Outlook, Mailgun, etc.
	 */
	public static function email_auth_passed( $raw_headers, $from_address ) {
		$raw_headers = (string) $raw_headers;
		if ( ! preg_match_all( '/^(?:ARC-)?Authentication-Results:(.*(?:\R[ \t].*)*)/mi', $raw_headers, $m ) ) {
			return false;
		}
		$domain = strtolower( substr( strrchr( (string) $from_address, '@' ), 1 ) );
		foreach ( $m[1] as $result ) {
			$result = strtolower( preg_replace( '/\s+/', ' ', $result ) );
			if ( preg_match( '/dmarc=pass/', $result ) ) {
				if ( '' === $domain || ! preg_match( '/header\.from=([^\s;]+)/', $result, $hf ) || self::domain_matches( $hf[1], $domain ) ) {
					return true;
				}
			}
			if ( preg_match_all( '/dkim=pass[^;]*?header\.(?:d|i)=@?([^\s;]+)/', $result, $dk ) ) {
				foreach ( $dk[1] as $d ) {
					if ( self::domain_matches( $d, $domain ) ) {
						return true;
					}
				}
			}
		}
		return false;
	}

	private static function domain_matches( $a, $b ) {
		$a = trim( strtolower( $a ), ' .' );
		$b = trim( strtolower( $b ), ' .' );
		return $a === $b || substr( $b, -strlen( '.' . $a ) ) === '.' . $a || substr( $a, -strlen( '.' . $b ) ) === '.' . $b;
	}

	/**
	 * Finds a category by name or slug ignoring case and accents.
	 *
	 * @param array $categories list of [ 'id' => int, 'name' => string, 'slug' => string ].
	 * @return int 0 when none matches.
	 */
	public static function match_category( $wanted, array $categories ) {
		$wanted = self::normalize( trim( (string) $wanted, " #\"'" ) );
		if ( '' === $wanted ) {
			return 0;
		}
		$slugged = trim( preg_replace( '/[^a-z0-9]+/', '-', $wanted ), '-' );
		foreach ( $categories as $cat ) {
			if ( self::normalize( $cat['name'] ) === $wanted || $cat['slug'] === $slugged || self::normalize( urldecode( $cat['slug'] ) ) === $wanted ) {
				return (int) $cat['id'];
			}
		}
		// Singular/plural: "Noticia" ↔ "Noticias", "Evento" ↔ "Eventos".
		foreach ( $categories as $cat ) {
			$name = self::normalize( $cat['name'] );
			if ( rtrim( $name, 's' ) === rtrim( $wanted, 's' ) || preg_replace( '/es$/', '', $name ) === preg_replace( '/es$/', '', $wanted ) ) {
				return (int) $cat['id'];
			}
		}
		return 0;
	}

	/**
	 * Plain text → paragraphs of HTML (used when there's no AI, and for WhatsApp text).
	 */
	public static function text_to_html( $text ) {
		$text  = trim( str_replace( "\r", '', (string) $text ) );
		$html  = '';
		$paras = preg_split( '/\n\s*\n/', $text );
		foreach ( $paras as $para ) {
			$para = trim( $para );
			if ( '' === $para ) {
				continue;
			}
			$lines = explode( "\n", $para );
			$items = array();
			foreach ( $lines as $l ) {
				if ( preg_match( '/^\s*(?:[-*•]|\d+[.)])\s+(.+)$/u', $l, $m ) ) {
					$items[] = $m[1];
				}
			}
			if ( count( $items ) === count( $lines ) && count( $items ) > 1 ) {
				$html .= '<ul>';
				foreach ( $items as $item ) {
					$html .= '<li>' . self::inline( $item ) . '</li>';
				}
				$html .= "</ul>\n";
				continue;
			}
			$html .= '<p>' . implode( "<br>\n", array_map( array( __CLASS__, 'inline' ), $lines ) ) . "</p>\n";
		}
		return $html;
	}

	/** WhatsApp-style *bold* and _italic_, with everything else escaped. */
	private static function inline( $text ) {
		$text = htmlspecialchars( trim( $text ), ENT_QUOTES, 'UTF-8' );
		$text = preg_replace( '/(?<![\w*])\*([^*\n]+)\*(?![\w*])/u', '<strong>$1</strong>', $text );
		$text = preg_replace( '/(?<![\w_])_([^_\n]+)_(?![\w_])/u', '<em>$1</em>', $text );
		return $text;
	}

	/**
	 * A title for posts built without AI: first sentence/line, cut at a word boundary.
	 */
	public static function guess_title( $text, $fallback = '' ) {
		foreach ( preg_split( '/\R/', trim( (string) $text ) ) as $line ) {
			$line = trim( strip_tags( $line ), " \t*_#" );
			if ( '' !== $line ) {
				if ( function_exists( 'mb_strlen' ) && mb_strlen( $line ) > 90 ) {
					$cut  = mb_substr( $line, 0, 90 );
					$line = preg_replace( '/\s+\S*$/u', '', $cut ) . '…';
				}
				return $line;
			}
		}
		return $fallback;
	}
}
