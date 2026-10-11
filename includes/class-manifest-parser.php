<?php
/**
 * Parses imsmanifest.xml files for SCORM 1.2 and SCORM 2004 packages.
 *
 * This class has no WordPress dependencies so it can be unit tested in isolation.
 *
 * @package TutorScorm
 */

namespace TutorScorm;

defined( 'ABSPATH' ) || defined( 'TUTOR_SCORM_TESTING' ) || exit;

class Manifest_Parser {

	const VERSION_12   = '1.2';
	const VERSION_2004 = '2004';

	/**
	 * Parse a manifest file.
	 *
	 * @param string $path Absolute path to imsmanifest.xml.
	 * @return array
	 * @throws \RuntimeException When the manifest cannot be read or is invalid.
	 */
	public static function parse_file( $path ) {
		if ( ! is_readable( $path ) ) {
			throw new \RuntimeException( 'imsmanifest.xml could not be read.' );
		}
		return self::parse_string( (string) file_get_contents( $path ) );
	}

	/**
	 * Parse manifest XML.
	 *
	 * @param string $xml Manifest contents.
	 * @return array {
	 *     @type string $version SCORM version: '1.2' or '2004'.
	 *     @type string $title   Package title.
	 *     @type array  $scos    Launchable items, in organization order.
	 * }
	 * @throws \RuntimeException When the manifest is invalid.
	 */
	public static function parse_string( $xml ) {
		$xml = preg_replace( '/^\xEF\xBB\xBF/', '', (string) $xml );
		if ( '' === trim( $xml ) ) {
			throw new \RuntimeException( 'imsmanifest.xml is empty.' );
		}
		// Refuse DOCTYPE declarations entirely: manifests never need them and they enable XXE / entity expansion attacks.
		if ( preg_match( '/<!DOCTYPE/i', $xml ) ) {
			throw new \RuntimeException( 'imsmanifest.xml must not contain a DOCTYPE declaration.' );
		}

		$dom      = new \DOMDocument();
		$previous = libxml_use_internal_errors( true );
		$loaded   = $dom->loadXML( $xml, LIBXML_NONET | LIBXML_COMPACT );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		if ( ! $loaded || ! $dom->documentElement || 'manifest' !== $dom->documentElement->localName ) {
			throw new \RuntimeException( 'imsmanifest.xml is not a valid IMS manifest.' );
		}

		$xpath   = new \DOMXPath( $dom );
		$root    = $dom->documentElement;
		$version = self::detect_version( $xpath, $xml );

		$resources = self::collect_resources( $xpath, $root );

		$organizations = self::child( $root, 'organizations' );
		$organization  = null;
		if ( $organizations ) {
			$default = $organizations->getAttribute( 'default' );
			foreach ( self::children( $organizations, 'organization' ) as $org ) {
				if ( null === $organization || ( '' !== $default && $org->getAttribute( 'identifier' ) === $default ) ) {
					$organization = $org;
				}
			}
		}

		$scos  = array();
		$title = '';
		if ( $organization ) {
			$title = self::child_text( $organization, 'title' );
			self::collect_items( $organization, $resources, $version, $scos, 0 );
		}

		// Some packages ship without organizations: fall back to the SCO resources directly.
		if ( empty( $scos ) ) {
			foreach ( $resources as $identifier => $resource ) {
				if ( '' === $resource['href'] ) {
					continue;
				}
				$scos[] = self::build_sco( $identifier, $identifier, $resource, '', $version, 0 );
			}
		}

		if ( empty( $scos ) ) {
			throw new \RuntimeException( 'The manifest does not declare any launchable SCO.' );
		}

		if ( '' === $title ) {
			$title = $scos[0]['title'];
		}

		return array(
			'version' => $version,
			'title'   => $title,
			'scos'    => $scos,
		);
	}

	/**
	 * Detect SCORM version from schemaversion metadata or declared namespaces.
	 *
	 * @param \DOMXPath $xpath XPath helper.
	 * @param string    $xml   Raw XML.
	 * @return string
	 */
	private static function detect_version( \DOMXPath $xpath, $xml ) {
		$nodes = $xpath->query( "/*[local-name()='manifest']/*[local-name()='metadata']/*[local-name()='schemaversion']" );
		if ( $nodes && $nodes->length ) {
			$schema = strtolower( trim( $nodes->item( 0 )->textContent ) );
			if ( false !== strpos( $schema, '2004' ) || false !== strpos( $schema, '1.3' ) ) {
				return self::VERSION_2004;
			}
			if ( false !== strpos( $schema, '1.2' ) ) {
				return self::VERSION_12;
			}
		}
		if ( false !== stripos( $xml, 'adlcp_v1p3' ) || false !== stripos( $xml, 'imsss' ) || false !== stripos( $xml, 'adlseq_v1p3' ) ) {
			return self::VERSION_2004;
		}
		return self::VERSION_12;
	}

	/**
	 * Build an identifier => resource map.
	 *
	 * @param \DOMXPath   $xpath XPath helper.
	 * @param \DOMElement $root  Manifest element.
	 * @return array
	 */
	private static function collect_resources( \DOMXPath $xpath, \DOMElement $root ) {
		$map           = array();
		$manifest_base = self::xml_base( $root );
		$container     = self::child( $root, 'resources' );
		if ( ! $container ) {
			return $map;
		}
		$container_base = self::xml_base( $container );

		foreach ( self::children( $container, 'resource' ) as $resource ) {
			$identifier = $resource->getAttribute( 'identifier' );
			if ( '' === $identifier ) {
				continue;
			}
			$map[ $identifier ] = array(
				'href'      => trim( $resource->getAttribute( 'href' ) ),
				'base'      => $manifest_base . $container_base . self::xml_base( $resource ),
				'scormtype' => strtolower( self::attribute_by_local_name( $resource, 'scormtype' ) ),
			);
		}
		return $map;
	}

	/**
	 * Walk the organization tree depth-first collecting launchable items.
	 *
	 * @param \DOMElement $parent    Organization or item element.
	 * @param array       $resources Resource map.
	 * @param string      $version   SCORM version.
	 * @param array       $scos      Collected SCOs (by reference).
	 * @param int         $depth     Nesting depth, used for indentation in the player menu.
	 */
	private static function collect_items( \DOMElement $parent, array $resources, $version, array &$scos, $depth ) {
		foreach ( self::children( $parent, 'item' ) as $item ) {
			$ref = $item->getAttribute( 'identifierref' );
			if ( '' !== $ref && isset( $resources[ $ref ] ) && '' !== $resources[ $ref ]['href'] ) {
				$identifier = $item->getAttribute( 'identifier' );
				$title      = self::child_text( $item, 'title' );
				$sco        = self::build_sco( '' !== $identifier ? $identifier : $ref, $title, $resources[ $ref ], $item->getAttribute( 'parameters' ), $version, $depth );

				if ( self::VERSION_12 === $version ) {
					$sco['masteryscore']    = self::child_text( $item, 'masteryscore' );
					$sco['maxtimeallowed']  = self::child_text( $item, 'maxtimeallowed' );
					$sco['timelimitaction'] = self::child_text( $item, 'timelimitaction' );
					$sco['datafromlms']     = self::child_text( $item, 'datafromlms' );
				} else {
					$sco['datafromlms']          = self::child_text( $item, 'dataFromLMS' );
					$sco['timelimitaction']      = self::child_text( $item, 'timeLimitAction' );
					$sco['completion_threshold'] = self::completion_threshold( $item );
					$sco['scaled_passing_score'] = self::scaled_passing_score( $item );
					$sco['maxtimeallowed']       = self::attempt_duration_limit( $item );
				}
				$scos[] = $sco;
			}
			self::collect_items( $item, $resources, $version, $scos, $depth + 1 );
		}
	}

	/**
	 * Build the normalized SCO description.
	 */
	private static function build_sco( $identifier, $title, array $resource, $parameters, $version, $depth ) {
		$href   = $resource['base'] . $resource['href'];
		$launch = self::append_parameters( $href, trim( (string) $parameters ) );

		return array(
			'identifier'           => (string) $identifier,
			'title'                => '' !== trim( (string) $title ) ? trim( (string) $title ) : (string) $identifier,
			'href'                 => $href,
			'launch'               => $launch,
			'external'             => (bool) preg_match( '#^[a-z][a-z0-9+.\-]*://#i', $href ),
			'scormtype'            => ( 'asset' === $resource['scormtype'] ) ? 'asset' : 'sco',
			'depth'                => (int) $depth,
			'masteryscore'         => '',
			'maxtimeallowed'       => '',
			'timelimitaction'      => '',
			'datafromlms'          => '',
			'completion_threshold' => '',
			'scaled_passing_score' => '',
			'version'              => $version,
		);
	}

	/**
	 * Append item parameters to a launch URL as described by the IMS CP spec.
	 *
	 * @param string $href       Launch URL.
	 * @param string $parameters Parameters attribute value.
	 * @return string
	 */
	public static function append_parameters( $href, $parameters ) {
		if ( '' === $parameters ) {
			return $href;
		}
		if ( '#' === $parameters[0] ) {
			return false === strpos( $href, '#' ) ? $href . $parameters : $href;
		}
		$parameters = ltrim( $parameters, '?&' );
		if ( '' === $parameters ) {
			return $href;
		}
		$fragment = '';
		$hash     = strpos( $href, '#' );
		if ( false !== $hash ) {
			$fragment = substr( $href, $hash );
			$href     = substr( $href, 0, $hash );
		}
		return $href . ( false === strpos( $href, '?' ) ? '?' : '&' ) . $parameters . $fragment;
	}

	private static function completion_threshold( \DOMElement $item ) {
		foreach ( $item->childNodes as $node ) {
			if ( $node instanceof \DOMElement && 'completionThreshold' === $node->localName ) {
				// SCORM 2004 4th edition uses an attribute; 3rd edition uses the element text.
				$value = $node->getAttribute( 'minProgressMeasure' );
				if ( '' === $value ) {
					$value = trim( $node->textContent );
				}
				return is_numeric( $value ) ? (string) $value : '';
			}
		}
		return '';
	}

	private static function scaled_passing_score( \DOMElement $item ) {
		$sequencing = self::child( $item, 'sequencing' );
		$objectives = $sequencing ? self::child( $sequencing, 'objectives' ) : null;
		$primary    = $objectives ? self::child( $objectives, 'primaryObjective' ) : null;
		if ( ! $primary || 'true' !== strtolower( $primary->getAttribute( 'satisfiedByMeasure' ) ) ) {
			return '';
		}
		$measure = self::child_text( $primary, 'minNormalizedMeasure' );
		return is_numeric( $measure ) ? (string) $measure : '1.0';
	}

	private static function attempt_duration_limit( \DOMElement $item ) {
		$sequencing = self::child( $item, 'sequencing' );
		$limits     = $sequencing ? self::child( $sequencing, 'limitConditions' ) : null;
		return $limits ? $limits->getAttribute( 'attemptAbsoluteDurationLimit' ) : '';
	}

	private static function xml_base( \DOMElement $element ) {
		$base = $element->getAttributeNS( 'http://www.w3.org/XML/1998/namespace', 'base' );
		if ( '' === $base ) {
			$base = $element->getAttribute( 'xml:base' );
		}
		$base = trim( $base );
		if ( '' !== $base && '/' !== substr( $base, -1 ) ) {
			$base .= '/';
		}
		return $base;
	}

	private static function attribute_by_local_name( \DOMElement $element, $name ) {
		foreach ( $element->attributes as $attribute ) {
			if ( strtolower( $attribute->localName ) === $name ) {
				return $attribute->value;
			}
		}
		return '';
	}

	/**
	 * @return \DOMElement[]
	 */
	private static function children( \DOMElement $parent, $local_name ) {
		$found = array();
		foreach ( $parent->childNodes as $node ) {
			if ( $node instanceof \DOMElement && $node->localName === $local_name ) {
				$found[] = $node;
			}
		}
		return $found;
	}

	private static function child( \DOMElement $parent, $local_name ) {
		$children = self::children( $parent, $local_name );
		return $children ? $children[0] : null;
	}

	private static function child_text( \DOMElement $parent, $local_name ) {
		$child = self::child( $parent, $local_name );
		return $child ? trim( $child->textContent ) : '';
	}
}
