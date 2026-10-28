<?php
/**
 * Structured Data (schema.org JSON-LD) — modules/29-schema-jsonld.php
 *
 * Migrated out of the Header Footer Code Manager plugin (2026-07) so the
 * markup lives in version control and HFCM can be retired. Emits the same
 * JSON-LD HFCM did, in <head>:
 *   - Organization      → every page          (was HFCM snippet #3)
 *   - Bock Fest Event   → page 10264 only      (was HFCM snippet #1)
 *   - Maifest Event     → page 4887 only       (was HFCM snippet #2)
 *
 * The blocks are curated static markup (no user input). To edit an event's
 * details each year, update the nowdoc below and deploy.
 *
 * Gate: gasf_site_enable_schema (default ON).
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

if ( gasf_site_enabled( 'gasf_site_enable_schema' ) ) {

	add_action( 'wp_head', 'gasf_schema_output', 20 );

	function gasf_schema_output() {
		gasf_schema_emit( gasf_schema_organization() );          // all pages
		// Stop emitting an Event block once the event has ended — Google flags
		// past-dated Event markup in Search Console until the yearly nowdoc
		// update. End instants below are duplicated from each block's endDate:
		// update BOTH when refreshing the year's dates.
		if ( is_page( 10264 ) && ! gasf_schema_expired( '2026-04-04T19:00:00-04:00' ) ) { gasf_schema_emit( gasf_schema_bockfest() ); }
		if ( is_page( 4887 )  && ! gasf_schema_expired( '2026-05-09T22:00:00-04:00' ) ) { gasf_schema_emit( gasf_schema_maifest() ); }
	}

	function gasf_schema_expired( $end_iso ) {
		$ts = strtotime( $end_iso );
		return $ts && $ts < time();
	}

	function gasf_schema_emit( $json ) {
		echo "\n<script type=\"application/ld+json\">\n" . $json . "\n</script>\n"; // phpcs:ignore -- curated static JSON-LD, not user input
	}

	function gasf_schema_organization() {
		return <<<'JSON'
{
  "@context": "https://schema.org",
  "@type": "Organization",
  "name": "German-American Society Friendship of Pinellas County",
  "url": "https://germantampabay.com",
  "logo": "https://germantampabay.com/wp-content/uploads/2020/03/cropped-GAFS-Logo-B.png",
  "telephone": "+17273506520",
  "email": "info@germantampabay.com",
  "address": {
    "@type": "PostalAddress",
    "streetAddress": "8098 66th Street North",
    "addressLocality": "Pinellas Park",
    "addressRegion": "FL",
    "postalCode": "33781",
    "addressCountry": "US"
  },
  "sameAs": [
    "https://www.facebook.com/GermanTampa",
    "https://www.instagram.com/germanamericansocietytampabay/"
  ]
}
JSON;
	}

	function gasf_schema_bockfest() {
		return <<<'JSON'
{
  "@context": "https://schema.org",
  "@type": "Event",
  "name": "Bock Fest – Get Poked!",
  "description": "Florida's most spirited celebration of German bock beer. Experience Bierstacheln (a red-hot poker plunged into your bock), live music by The Rum Syndicate, the Great Bock Race, Stein Hoisting Contest, Jump the Goat dance by the Enzianer Schuhplattler Verein, German bratwurst, desserts, and more. Family friendly!",
  "url": "https://germantampabay.com/bockfest/",
  "image": "https://i0.wp.com/germantampabay.com/wp-content/uploads/2024/08/Logo1.png?w=1380&ssl=1",
  "startDate": "2026-04-04T12:00:00-04:00",
  "endDate": "2026-04-04T19:00:00-04:00",
  "eventStatus": "https://schema.org/EventScheduled",
  "eventAttendanceMode": "https://schema.org/OfflineEventAttendanceMode",
  "location": {
    "@type": "Place",
    "name": "German-American Society Friendship of Pinellas County",
    "telephone": "+17273506520",
    "address": {
      "@type": "PostalAddress",
      "streetAddress": "8098 66th Street North",
      "addressLocality": "Pinellas Park",
      "addressRegion": "FL",
      "postalCode": "33781",
      "addressCountry": "US"
    },
    "geo": {
      "@type": "GeoCoordinates",
      "latitude": 27.8333,
      "longitude": -82.7200
    }
  },
  "organizer": {
    "@type": "Organization",
    "name": "German-American Society Friendship of Pinellas County",
    "url": "https://germantampabay.com",
    "telephone": "+17273506520",
    "email": "info@germantampabay.com"
  },
  "offers": [
    {
      "@type": "Offer",
      "name": "General Admission",
      "price": "8.00",
      "priceCurrency": "USD",
      "availability": "https://schema.org/InStock",
      "validFrom": "2026-01-01",
      "url": "https://germantampabay.com/bockfest/",
      "description": "Non-members"
    },
    {
      "@type": "Offer",
      "name": "Member / Military Admission",
      "price": "5.00",
      "priceCurrency": "USD",
      "availability": "https://schema.org/InStock",
      "validFrom": "2026-01-01",
      "url": "https://germantampabay.com/bockfest/",
      "description": "Members and active military with ID"
    },
    {
      "@type": "Offer",
      "name": "Children 12 and Under",
      "price": "0.00",
      "priceCurrency": "USD",
      "availability": "https://schema.org/InStock",
      "validFrom": "2026-01-01",
      "url": "https://germantampabay.com/bockfest/",
      "description": "Free for children 12 and under"
    }
  ],
  "performer": {
    "@type": "MusicGroup",
    "name": "The Rum Syndicate",
    "url": "https://rumsyndicate.live/index.php/sample-page/"
  },
  "keywords": [
    "Bock Fest",
    "German beer festival",
    "Bierstacheln",
    "Tampa Bay German events",
    "Pinellas Park events",
    "bock beer Florida",
    "Schuhplattler",
    "German-American Society"
  ],
  "inLanguage": "en",
  "isAccessibleForFree": false,
  "typicalAgeRange": "0+"
}
JSON;
	}

	function gasf_schema_maifest() {
		return <<<'JSON'
{
  "@context": "https://schema.org",
  "@type": "Event",
  "name": "Maifest in the Biergarten",
  "description": "A traditional German Maifest celebration around an authentic Maibaum (Maypole). Enjoy live music, traditional Schuhplattler Maibaum dances, German roasted chicken, bratwurst, pretzels, sauerkraut, Maibock beer, a vendor village, raffle, and an after-party with live music. Open to the public. Family friendly and dog-friendly with a SPCA donation drive.",
  "url": "https://germantampabay.com/maifest/",
  "image": "https://i0.wp.com/germantampabay.com/wp-content/uploads/2025/04/Maifest-final.jpeg?fit=1545%2C2000&ssl=1",
  "startDate": "2026-05-09T12:00:00-04:00",
  "endDate": "2026-05-09T22:00:00-04:00",
  "eventStatus": "https://schema.org/EventScheduled",
  "eventAttendanceMode": "https://schema.org/OfflineEventAttendanceMode",
  "location": {
    "@type": "Place",
    "name": "German-American Society Friendship of Pinellas County",
    "telephone": "+17273506520",
    "address": {
      "@type": "PostalAddress",
      "streetAddress": "8098 66th Street North",
      "addressLocality": "Pinellas Park",
      "addressRegion": "FL",
      "postalCode": "33781",
      "addressCountry": "US"
    },
    "geo": {
      "@type": "GeoCoordinates",
      "latitude": 27.8333,
      "longitude": -82.7200
    }
  },
  "organizer": {
    "@type": "Organization",
    "name": "German-American Society Friendship of Pinellas County",
    "url": "https://germantampabay.com",
    "telephone": "+17273506520",
    "email": "info@germantampabay.com"
  },
  "offers": [
    {
      "@type": "Offer",
      "name": "General Admission",
      "price": "8.00",
      "priceCurrency": "USD",
      "availability": "https://schema.org/InStock",
      "validFrom": "2026-01-01",
      "url": "https://germantampabay.com/maifest/",
      "description": "Non-members"
    },
    {
      "@type": "Offer",
      "name": "Member Admission",
      "price": "5.00",
      "priceCurrency": "USD",
      "availability": "https://schema.org/InStock",
      "validFrom": "2026-01-01",
      "url": "https://germantampabay.com/maifest/",
      "description": "German-American Society members"
    }
  ],
  "performer": [
    { "@type": "MusicGroup", "name": "DeLeon" },
    { "@type": "PerformingGroup", "name": "Enzianer Schuhplattler Verein" },
    { "@type": "PerformingGroup", "name": "Island Witches Dance Troupe" },
    { "@type": "MusicGroup", "name": "Autobahn Karl" }
  ],
  "subEvent": {
    "@type": "Event",
    "name": "Maifest After Party",
    "startDate": "2026-05-09T19:00:00-04:00",
    "endDate": "2026-05-09T22:00:00-04:00",
    "description": "After party inside the Main Hall with live music by Autobahn Karl.",
    "location": {
      "@type": "Place",
      "name": "German-American Society – Main Hall",
      "address": {
        "@type": "PostalAddress",
        "streetAddress": "8098 66th Street North",
        "addressLocality": "Pinellas Park",
        "addressRegion": "FL",
        "postalCode": "33781",
        "addressCountry": "US"
      }
    }
  },
  "keywords": [
    "Maifest",
    "Maypole",
    "Maibaum",
    "Tampa Bay German events",
    "Pinellas Park events",
    "Maibock beer",
    "German-American Society",
    "Schuhplattler",
    "German festival Florida"
  ],
  "inLanguage": "en",
  "isAccessibleForFree": false,
  "typicalAgeRange": "0+"
}
JSON;
	}
}
