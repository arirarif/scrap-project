<?php
/**
 * HTML Parser — extracts product spec features from Shopify body_html.
 *
 * PHP port of sev-scraper/html_parser.py.
 *
 * Shopify body_html contains HTML like:
 *   <table>
 *     <tr><td>Nennleistung (kVA)</td><td>5.5 kVA</td></tr>
 *     <tr><td>Kraftstoff</td>      <td>Benzin</td>   </tr>
 *   </table>
 *
 * This converts that to the plugin's Features format:
 *   [
 *     {"Id":"nennleistung_kva", "Name":"Nennleistung (kVA)", "Value":[{"Name":"5.5 kVA"}]},
 *     {"Id":"kraftstoff",       "Name":"Kraftstoff",         "Value":[{"Name":"Benzin"}]},
 *   ]
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SEV_Html_Parser {

	/**
	 * Parse spec tables from Shopify product HTML.
	 *
	 * @param  string $html  Raw body_html from a Shopify product.
	 * @return array         Features array ready for WC attributes.
	 */
	public function parse_features( string $html ): array {
		if ( empty( $html ) ) {
			return [];
		}

		$features   = [];
		$seen_names = []; // deduplicate by normalised lowercase name

		// Load HTML safely — suppress malformed-HTML warnings.
		// The xml encoding prefix forces UTF-8 without mangling German characters.
		libxml_use_internal_errors( true );
		$dom = new DOMDocument();
		$dom->loadHTML( '<?xml encoding="utf-8" ?>' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );
		libxml_clear_errors();
		libxml_use_internal_errors( false );

		$tables = $dom->getElementsByTagName( 'table' );

		foreach ( $tables as $table ) {
			$rows = $table->getElementsByTagName( 'tr' );

			foreach ( $rows as $row ) {
				// Only direct td/th children — avoids picking up cells from nested tables.
				$cells = $this->get_direct_cells( $row );

				if ( count( $cells ) < 2 ) {
					continue;
				}

				$name  = $this->get_text( $cells[0] );
				$value = $this->get_text( $cells[1] );

				// Skip empty or degenerate rows
				if ( $name === '' || $value === '' ) {
					continue;
				}
				if ( $name === $value ) {
					continue; // header row where both cells are identical
				}
				if ( mb_strlen( $name, 'UTF-8' ) > 80 ) {
					continue; // looks like a section description, not a spec label
				}

				// Deduplicate by normalised lowercase name
				$name_key = mb_strtolower( $name, 'UTF-8' );
				if ( isset( $seen_names[ $name_key ] ) ) {
					continue;
				}
				$seen_names[ $name_key ] = true;

				$features[] = [
					'Id'    => $this->make_slug( $name ),
					'Name'  => $name,
					'Value' => [ [ 'Name' => $value ] ],
				];
			}
		}

		return $features;
	}

	// ── Private helpers ────────────────────────────────────────────────────────

	/**
	 * Collect only the direct td/th child elements of a <tr> row.
	 * This prevents picking up cells from nested tables inside a cell.
	 *
	 * @param  DOMElement $row
	 * @return DOMElement[]
	 */
	private function get_direct_cells( DOMElement $row ): array {
		$cells = [];

		foreach ( $row->childNodes as $node ) {
			if ( ! ( $node instanceof DOMElement ) ) {
				continue;
			}
			$tag = strtolower( $node->nodeName );
			if ( $tag === 'td' || $tag === 'th' ) {
				$cells[] = $node;
			}
		}

		return $cells;
	}

	/**
	 * Get normalised plain text from a DOM element.
	 * Collapses all whitespace (including non-breaking spaces) to single spaces.
	 *
	 * @param  DOMElement $node
	 * @return string
	 */
	private function get_text( DOMElement $node ): string {
		$text = $node->textContent;
		// Replace UTF-8 non-breaking space (U+00A0) with regular space
		$text = str_replace( "\xc2\xa0", ' ', $text );
		// Collapse all whitespace
		$text = preg_replace( '/\s+/u', ' ', $text );
		return trim( $text );
	}

	/**
	 * Generate a URL-safe slug from a feature name.
	 *
	 * Rules (mirrors Python version):
	 *  - Lowercase (UTF-8 aware)
	 *  - Keep: a-z, 0-9, German umlauts (äöüß), spaces
	 *  - Replace spaces → underscores
	 *  - Cap at 40 characters
	 *
	 * Note: WooCommerce attribute slugs have a hard 28-char limit.
	 * SEV_Product_Mapper handles truncation when registering global attributes.
	 *
	 * @param  string $text  Feature label, e.g. "Nennleistung (kVA)".
	 * @return string        Slug,          e.g. "nennleistung_kva".
	 */
	private function make_slug( string $text ): string {
		$slug = mb_strtolower( $text, 'UTF-8' );
		// Strip anything that's not a letter, digit, German umlaut, or space
		$slug = preg_replace( '/[^a-z0-9äöüß\s]/u', '', $slug );
		// Spaces → underscores (collapse multiples)
		$slug = preg_replace( '/\s+/u', '_', trim( $slug ) );
		// Collapse repeated underscores
		$slug = preg_replace( '/_+/', '_', $slug );
		// Trim leading/trailing underscores
		$slug = trim( $slug, '_' );
		// Cap length
		return mb_substr( $slug, 0, 40, 'UTF-8' );
	}
}
