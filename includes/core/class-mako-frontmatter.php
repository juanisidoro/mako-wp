<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Mako_Frontmatter {

	/**
	 * Build YAML frontmatter string from data array.
	 */
	public function build( array $data ): string {
		$yaml = "---\n";

		// Required fields (order matters for readability).
		$yaml .= 'mako: ' . $this->yaml_value( $data['mako'] ?? MAKO_SPEC_VERSION ) . "\n";
		$yaml .= 'type: ' . ( $data['type'] ?? 'custom' ) . "\n";
		$yaml .= 'entity: ' . $this->yaml_string( $data['entity'] ?? 'Unknown' ) . "\n";
		$yaml .= 'updated: ' . $this->yaml_string( $data['updated'] ?? gmdate( 'Y-m-d' ) ) . "\n";
		$yaml .= 'tokens: ' . (int) ( $data['tokens'] ?? 0 ) . "\n";
		$yaml .= 'language: ' . ( $data['language'] ?? 'en' ) . "\n";

		// Optional fields.
		if ( ! empty( $data['summary'] ) ) {
			$yaml .= 'summary: ' . $this->yaml_string( $data['summary'] ) . "\n";
		}

		if ( ! empty( $data['freshness'] ) ) {
			$yaml .= 'freshness: ' . $data['freshness'] . "\n";
		}

		if ( ! empty( $data['audience'] ) ) {
			$yaml .= 'audience: ' . $data['audience'] . "\n";
		}

		if ( ! empty( $data['canonical'] ) ) {
			$yaml .= 'canonical: ' . $this->yaml_string( $data['canonical'] ) . "\n";
		}

		if ( ! empty( $data['media'] ) && is_array( $data['media'] ) ) {
			$yaml .= "media:\n";
			$media = $data['media'];

			if ( ! empty( $media['cover'] ) && is_array( $media['cover'] ) ) {
				$yaml .= "  cover:\n";
				$yaml .= '    url: ' . $this->yaml_string( $media['cover']['url'] ) . "\n";
				$yaml .= '    alt: ' . $this->yaml_string( $media['cover']['alt'] ) . "\n";
			}

			foreach ( array( 'images', 'video', 'audio', 'interactive', 'downloads' ) as $count_key ) {
				if ( ! empty( $media[ $count_key ] ) ) {
					$yaml .= '  ' . $count_key . ': ' . (int) $media[ $count_key ] . "\n";
				}
			}
		}

		if ( ! empty( $data['tags'] ) && is_array( $data['tags'] ) ) {
			$yaml .= "tags:\n";
			foreach ( $data['tags'] as $tag ) {
				$yaml .= '  - ' . $this->yaml_string( $tag ) . "\n";
			}
		}

		if ( ! empty( $data['actions'] ) && is_array( $data['actions'] ) ) {
			$yaml .= "actions:\n";
			foreach ( $data['actions'] as $action ) {
				$yaml .= '  - name: ' . $action['name'] . "\n";
				$yaml .= '    description: ' . $this->yaml_string( $action['description'] ) . "\n";
				if ( ! empty( $action['endpoint'] ) ) {
					$yaml .= '    endpoint: ' . $action['endpoint'] . "\n";
				}
				if ( ! empty( $action['method'] ) ) {
					$yaml .= '    method: ' . $action['method'] . "\n";
				}
				if ( ! empty( $action['params'] ) && is_array( $action['params'] ) ) {
					$yaml .= "    params:\n";
					foreach ( $action['params'] as $param ) {
						$yaml .= '      - name: ' . $param['name'] . "\n";
						$yaml .= '        type: ' . ( $param['type'] ?? 'string' ) . "\n";
						$yaml .= '        required: ' . ( ! empty( $param['required'] ) ? 'true' : 'false' ) . "\n";
						if ( ! empty( $param['description'] ) ) {
							$yaml .= '        description: ' . $this->yaml_string( $param['description'] ) . "\n";
						}
					}
				}
			}
		}

		if ( ! empty( $data['links'] ) ) {
			$yaml .= "links:\n";
			if ( ! empty( $data['links']['internal'] ) ) {
				$yaml .= "  internal:\n";
				foreach ( $data['links']['internal'] as $link ) {
					$yaml .= '    - url: ' . $link['url'] . "\n";
					$yaml .= '      context: ' . $this->yaml_string( $link['context'] ) . "\n";
					if ( ! empty( $link['type'] ) ) {
						$yaml .= '      type: ' . $link['type'] . "\n";
					}
				}
			}
			if ( ! empty( $data['links']['external'] ) ) {
				$yaml .= "  external:\n";
				foreach ( $data['links']['external'] as $link ) {
					$yaml .= '    - url: ' . $link['url'] . "\n";
					$yaml .= '      context: ' . $this->yaml_string( $link['context'] ) . "\n";
					if ( ! empty( $link['type'] ) ) {
						$yaml .= '      type: ' . $link['type'] . "\n";
					}
				}
			}
		}

		$yaml .= "---\n";

		return $yaml;
	}

	/**
	 * Parse YAML frontmatter from a MAKO file string.
	 */
	public function parse( string $content ): ?array {
		if ( ! preg_match( '/^---\n(.+?)\n---/s', $content, $matches ) ) {
			return null;
		}

		$lines = explode( "\n", $matches[1] );
		$data  = array();

		foreach ( $lines as $line ) {
			if ( preg_match( '/^(\w[\w-]*)\s*:\s*(.*)$/', $line, $m ) ) {
				$key   = $m[1];
				$value = trim( $m[2] );
				// Remove surrounding quotes.
				if ( preg_match( '/^"(.*)"$/', $value, $q ) ) {
					$value = stripcslashes( $q[1] );
				}
				$data[ $key ] = $value;
			}
		}

		if ( isset( $data['tokens'] ) ) {
			$data['tokens'] = (int) $data['tokens'];
		}

		return $data;
	}

	/**
	 * Escape and quote a string for YAML output.
	 */
	private function yaml_string( string $value ): string {
		// If simple alphanumeric/dash/dot/slash, no quotes needed.
		if ( preg_match( '/^[a-zA-Z0-9\-_.\/]+$/', $value ) ) {
			return $value;
		}

		$escaped = str_replace( '\\', '\\\\', $value );
		$escaped = str_replace( '"', '\\"', $escaped );
		$escaped = str_replace( "\n", '\\n', $escaped );

		return '"' . $escaped . '"';
	}

	/**
	 * Format a value for YAML (handles version strings).
	 */
	private function yaml_value( string $value ): string {
		// Ensure version is quoted so YAML doesn't interpret "1.0" as float.
		if ( preg_match( '/^\d+\.\d+$/', $value ) ) {
			return '"' . $value . '"';
		}
		return $value;
	}
}
