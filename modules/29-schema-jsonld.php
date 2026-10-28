<?php
/**
 * Structured Data (schema.org JSON-LD) — modules/29-schema-jsonld.php
 *
 * Migrated out of the Header Footer Code Manager plugin (2026-07) so the
 * markup lives in version control. Emits, in <head>:
 *   - Organization    → every page
 *   - Bock Fest Event → page 10264 only
 *   - Maifest Event   → page 4887 only
 *
 * The festival blocks take their DATES from the calendar. Until 2026-10 they
 * were nowdocs with the year's dates typed in, and the block was suppressed
 * once that date passed — so after each festival the page emitted nothing
 * until somebody remembered to edit this file and deploy. Both blocks had
 * been dark since May. Now each festival names the gasf_event it belongs to
 * (a title keyword), and the next published occurrence on the calendar
 * supplies startDate/endDate; no upcoming occurrence → no Event block, which
 * is the right answer rather than a stale one. The copy (description,
 * prices, performers) is still curated here, because the event post does
 * not carry it; edit it when it changes, but a wrong price is a cosmetic
 * error where a past date is a Search Console one.
 *
 * Gate: gasf_site_enable_schema (default ON).
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( gasf_site_enabled( 'gasf_site_enable_schema' ) ) {

	add_action( 'wp_head', 'gasf_schema_output', 20 );

	function gasf_schema_output() {
		gasf_schema_emit( gasf_schema_organization() );
		foreach ( gasf_schema_festivals() as $f ) {
			if ( ! is_page( $f['page'] ) ) { continue; }
			$when = gasf_schema_next_occurrence( $f['keyword'], $f['hours'] );
			if ( $when ) { gasf_schema_emit( $f['build']( $when['start'], $when['end'] ) ); }
		}
	}

	function gasf_schema_emit( $data ) {
		$json = is_string( $data ) ? $data : wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT );
		echo "\n<script type=\"application/ld+json\">\n" . $json . "\n</script>\n"; // phpcs:ignore WordPress.Security.EscapeOutput -- curated JSON-LD built with wp_json_encode
	}

	/**
	 * Start/end (DateTimeImmutable, site timezone) of the next published
	 * gasf_event whose title contains $keyword and has not ended yet, or null.
	 * Cached for an hour per keyword; the calendar changes rarely and this runs
	 * on every view of the festival page.
	 *
	 * GASF-Events stores `_gasf_start` ('Y-m-d H:i:s', site-local) and
	 * `_gasf_start_ts`; an end time is read from `_gasf_end` when present and
	 * otherwise taken as start + $hours (the festival's usual length).
	 */
	function gasf_schema_next_occurrence( $keyword, $hours ) {
		$ck  = 'gasf_schema_next_' . md5( $keyword );
		$hit = get_transient( $ck );
		if ( is_array( $hit ) ) {
			return ! empty( $hit['start'] ) ? array(
				'start' => new DateTimeImmutable( $hit['start'], wp_timezone() ),
				'end'   => new DateTimeImmutable( $hit['end'], wp_timezone() ),
			) : null;
		}
		global $wpdb;
		$ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT p.ID FROM {$wpdb->posts} p
			 JOIN {$wpdb->postmeta} ts ON ts.post_id = p.ID AND ts.meta_key = '_gasf_start_ts'
			 WHERE p.post_type = 'gasf_event' AND p.post_status = 'publish'
			   AND p.post_title LIKE %s AND ts.meta_value + 0 >= %d
			 ORDER BY ts.meta_value + 0 ASC LIMIT 3",
			'%' . $wpdb->esc_like( $keyword ) . '%', time() - DAY_IN_SECONDS
		) );
		$found = null;
		foreach ( $ids as $id ) {
			$start = date_create_immutable_from_format( 'Y-m-d H:i:s', (string) get_post_meta( $id, '_gasf_start', true ), wp_timezone() );
			if ( ! $start ) { continue; }
			$end_raw = (string) get_post_meta( $id, '_gasf_end', true );
			$end     = $end_raw ? date_create_immutable_from_format( 'Y-m-d H:i:s', $end_raw, wp_timezone() ) : false;
			if ( ! $end || $end <= $start ) { $end = $start->modify( '+' . (int) $hours . ' hours' ); }
			if ( $end->getTimestamp() < time() ) { continue; } // already over
			$found = array( 'start' => $start, 'end' => $end );
			break;
		}
		set_transient( $ck, $found ? array( 'start' => $found['start']->format( 'Y-m-d H:i:s' ), 'end' => $found['end']->format( 'Y-m-d H:i:s' ) ) : array( 'start' => '' ), HOUR_IN_SECONDS );
		return $found;
	}
	add_action( 'save_post_gasf_event', function () {
		foreach ( gasf_schema_festivals() as $f ) { delete_transient( 'gasf_schema_next_' . md5( $f['keyword'] ) ); }
	} );

	/* ---------- shared pieces ---------- */

	function gasf_schema_address() {
		return array(
			'@type'           => 'PostalAddress',
			'streetAddress'   => '8098 66th Street North',
			'addressLocality' => 'Pinellas Park',
			'addressRegion'   => 'FL',
			'postalCode'      => '33781',
			'addressCountry'  => 'US',
		);
	}

	function gasf_schema_place() {
		return array(
			'@type'     => 'Place',
			'name'      => 'German-American Society Friendship of Pinellas County',
			'telephone' => '+17273506520',
			'address'   => gasf_schema_address(),
			'geo'       => array( '@type' => 'GeoCoordinates', 'latitude' => 27.8333, 'longitude' => -82.7200 ),
		);
	}

	function gasf_schema_organizer() {
		return array(
			'@type'     => 'Organization',
			'name'      => 'German-American Society Friendship of Pinellas County',
			'url'       => 'https://germantampabay.com',
			'telephone' => '+17273506520',
			'email'     => 'info@germantampabay.com',
		);
	}

	function gasf_schema_offer( $name, $price, $url, $desc, $year ) {
		return array(
			'@type'         => 'Offer',
			'name'          => $name,
			'price'         => $price,
			'priceCurrency' => 'USD',
			'availability'  => 'https://schema.org/InStock',
			'validFrom'     => $year . '-01-01',
			'url'           => $url,
			'description'   => $desc,
		);
	}

	function gasf_schema_organization() {
		return array(
			'@context'  => 'https://schema.org',
			'@type'     => 'Organization',
			'name'      => 'German-American Society Friendship of Pinellas County',
			'url'       => 'https://germantampabay.com',
			'logo'      => 'https://germantampabay.com/wp-content/uploads/2020/03/cropped-GAFS-Logo-B.png',
			'telephone' => '+17273506520',
			'email'     => 'info@germantampabay.com',
			'address'   => gasf_schema_address(),
			'sameAs'    => array(
				'https://www.facebook.com/GermanTampa',
				'https://www.instagram.com/germanamericansocietytampabay/',
			),
		);
	}

	/* ---------- the festivals ----------
	 * page    = the WordPress page the block is emitted on
	 * keyword = matched (case-insensitively) against gasf_event titles
	 * hours   = the usual length, used when the event post has no end time
	 * build   = fn(start, end) → JSON-LD array; all curated copy lives here */
	function gasf_schema_festivals() {
		return array(
			array(
				'page'    => 10264,
				'keyword' => 'Bock Fest',
				'hours'   => 7,
				'build'   => function ( DateTimeImmutable $start, DateTimeImmutable $end ) {
					$url = 'https://germantampabay.com/bockfest/';
					return array(
						'@context'            => 'https://schema.org',
						'@type'               => 'Event',
						'name'                => 'Bock Fest – Get Poked!',
						'description'         => "Florida's most spirited celebration of German bock beer. Experience Bierstacheln (a red-hot poker plunged into your bock), live music by The Rum Syndicate, the Great Bock Race, Stein Hoisting Contest, Jump the Goat dance by the Enzianer Schuhplattler Verein, German bratwurst, desserts, and more. Family friendly!",
						'url'                 => $url,
						'image'               => 'https://i0.wp.com/germantampabay.com/wp-content/uploads/2024/08/Logo1.png?w=1380&ssl=1',
						'startDate'           => $start->format( 'c' ),
						'endDate'             => $end->format( 'c' ),
						'eventStatus'         => 'https://schema.org/EventScheduled',
						'eventAttendanceMode' => 'https://schema.org/OfflineEventAttendanceMode',
						'location'            => gasf_schema_place(),
						'organizer'           => gasf_schema_organizer(),
						'offers'              => array(
							gasf_schema_offer( 'General Admission', '8.00', $url, 'Non-members', $start->format( 'Y' ) ),
							gasf_schema_offer( 'Member / Military Admission', '5.00', $url, 'Members and active military with ID', $start->format( 'Y' ) ),
							gasf_schema_offer( 'Children 12 and Under', '0.00', $url, 'Free for children 12 and under', $start->format( 'Y' ) ),
						),
						'performer'           => array( '@type' => 'MusicGroup', 'name' => 'The Rum Syndicate', 'url' => 'https://rumsyndicate.live/index.php/sample-page/' ),
						'keywords'            => array( 'Bock Fest', 'German beer festival', 'Bierstacheln', 'Tampa Bay German events', 'Pinellas Park events', 'bock beer Florida', 'Schuhplattler', 'German-American Society' ),
						'inLanguage'          => 'en',
						'isAccessibleForFree' => false,
						'typicalAgeRange'     => '0+',
					);
				},
			),
			array(
				'page'    => 4887,
				'keyword' => 'Maifest',
				'hours'   => 10,
				'build'   => function ( DateTimeImmutable $start, DateTimeImmutable $end ) {
					$url   = 'https://germantampabay.com/maifest/';
					$after = $end->modify( '-3 hours' ); // the after-party fills the last three hours
					return array(
						'@context'            => 'https://schema.org',
						'@type'               => 'Event',
						'name'                => 'Maifest in the Biergarten',
						'description'         => 'A traditional German Maifest celebration around an authentic Maibaum (Maypole). Enjoy live music, traditional Schuhplattler Maibaum dances, German roasted chicken, bratwurst, pretzels, sauerkraut, Maibock beer, a vendor village, raffle, and an after-party with live music. Open to the public. Family friendly and dog-friendly with a SPCA donation drive.',
						'url'                 => $url,
						'image'               => 'https://i0.wp.com/germantampabay.com/wp-content/uploads/2025/04/Maifest-final.jpeg?fit=1545%2C2000&ssl=1',
						'startDate'           => $start->format( 'c' ),
						'endDate'             => $end->format( 'c' ),
						'eventStatus'         => 'https://schema.org/EventScheduled',
						'eventAttendanceMode' => 'https://schema.org/OfflineEventAttendanceMode',
						'location'            => gasf_schema_place(),
						'organizer'           => gasf_schema_organizer(),
						'offers'              => array(
							gasf_schema_offer( 'General Admission', '8.00', $url, 'Non-members', $start->format( 'Y' ) ),
							gasf_schema_offer( 'Member Admission', '5.00', $url, 'German-American Society members', $start->format( 'Y' ) ),
						),
						'performer'           => array(
							array( '@type' => 'MusicGroup', 'name' => 'DeLeon' ),
							array( '@type' => 'PerformingGroup', 'name' => 'Enzianer Schuhplattler Verein' ),
							array( '@type' => 'PerformingGroup', 'name' => 'Island Witches Dance Troupe' ),
							array( '@type' => 'MusicGroup', 'name' => 'Autobahn Karl' ),
						),
						'subEvent'            => array(
							'@type'       => 'Event',
							'name'        => 'Maifest After Party',
							'startDate'   => $after->format( 'c' ),
							'endDate'     => $end->format( 'c' ),
							'description' => 'After party inside the Main Hall with live music by Autobahn Karl.',
							'location'    => array( '@type' => 'Place', 'name' => 'German-American Society – Main Hall', 'address' => gasf_schema_address() ),
						),
						'keywords'            => array( 'Maifest', 'Maypole', 'Maibaum', 'Tampa Bay German events', 'Pinellas Park events', 'Maibock beer', 'German-American Society', 'Schuhplattler', 'German festival Florida' ),
						'inLanguage'          => 'en',
						'isAccessibleForFree' => false,
						'typicalAgeRange'     => '0+',
					);
				},
			),
		);
	}
}
