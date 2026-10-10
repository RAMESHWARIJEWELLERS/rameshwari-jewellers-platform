<?php
/**
 * Lead persistence.
 *
 * @package Rameshwari
 */

namespace Rameshwari\Core\WhatsApp;

/**
 * Lead storage.
 *
 * Inserts a lead row, or refreshes an existing one when the same valid normalised
 * phone enquired about the same source and object inside the window. Without a
 * valid phone nothing is deduplicated: the IP hash is never a substitute identity,
 * so no-phone enquiries always create their own rows.
 */
final class LeadRepository {

	public const WINDOW = 1800;

	/**
	 * Stores a lead, or updates the matching recent one.
	 *
	 * @param array<string,mixed> $lead source, object_id, object_code, customer_id, phone, message, page_url, referrer, ip_hash.
	 * @return int Lead ID, or 0 when nothing was stored.
	 */
	public function record( array $lead ): int {
		global $wpdb;

		$table = $wpdb->prefix . 'rj_leads';
		$match = $this->recent( $table, $lead );

		if ( $match > 0 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Lead table write.
			$done = $wpdb->update(
				$table,
				array(
					'message'  => (string) $lead['message'],
					'page_url' => (string) $lead['page_url'],
					'referrer' => (string) $lead['referrer'],
				),
				array( 'id' => $match ),
				array( '%s', '%s', '%s' ),
				array( '%d' )
			);

			return false === $done ? 0 : $match;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Lead table write.
		$done = $wpdb->insert(
			$table,
			array(
				'source'      => (string) $lead['source'],
				'channel'     => 'whatsapp',
				'object_id'   => (int) $lead['object_id'],
				'object_code' => (string) $lead['object_code'],
				'customer_id' => (int) $lead['customer_id'],
				'phone'       => (string) $lead['phone'],
				'message'     => (string) $lead['message'],
				'page_url'    => (string) $lead['page_url'],
				'referrer'    => (string) $lead['referrer'],
				'ip_hash'     => (string) $lead['ip_hash'],
				'spam_score'  => 0,
				'status'      => 'new',
				'created_at'  => current_time( 'mysql', true ),
			),
			array( '%s', '%s', '%d', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s' )
		);

		return ( false === $done || 1 !== $done ) ? 0 : (int) $wpdb->insert_id;
	}

	/**
	 * The ID of a matching lead inside the window, or 0.
	 *
	 * @param string              $table Table name.
	 * @param array<string,mixed> $lead  Lead.
	 * @return int
	 */
	private function recent( string $table, array $lead ): int {
		global $wpdb;

		$phone = (string) $lead['phone'];

		if ( '' === $phone ) {
			return 0;
		}
		$since = gmdate( 'Y-m-d H:i:s', time() - self::WINDOW );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Fixed table and column names; every value is prepared.
		$id = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE source = %s AND object_id = %d AND phone = %s AND created_at >= %s ORDER BY id DESC LIMIT 1", (string) $lead['source'], (int) $lead['object_id'], $phone, $since ) );

		return null === $id ? 0 : (int) $id;
	}
}
