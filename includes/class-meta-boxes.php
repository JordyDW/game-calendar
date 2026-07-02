<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GC_Meta_Boxes {

	public function __construct() {
		add_action( 'add_meta_boxes', array( $this, 'register' ) );
		add_action( 'save_post', array( $this, 'save' ) );
	}

	public function register() {
		add_meta_box(
			'gc_release_details',
			__( 'Release Details', 'game-calendar' ),
			array( $this, 'render_release_meta_box' ),
			'gc_release',
			'normal',
			'high'
		);

		add_meta_box(
			'gc_event_details',
			__( 'Event Details', 'game-calendar' ),
			array( $this, 'render_event_meta_box' ),
			'gc_event',
			'normal',
			'high'
		);

		add_meta_box(
			'gc_dlc_details',
			__( 'DLC / Update Details', 'game-calendar' ),
			array( $this, 'render_dlc_meta_box' ),
			'gc_dlc',
			'normal',
			'high'
		);
	}

	public function render_release_meta_box( $post ) {
		wp_nonce_field( 'gc_release_save', 'gc_release_nonce' );
		$release_date = get_post_meta( $post->ID, 'gc_release_date', true );
		$developer    = get_post_meta( $post->ID, 'gc_developer', true );
		$publisher    = get_post_meta( $post->ID, 'gc_publisher', true );
		$genre        = get_post_meta( $post->ID, 'gc_genre', true );
		$igdb_id      = get_post_meta( $post->ID, 'gc_igdb_id', true );
		$cover_url    = get_post_meta( $post->ID, 'gc_cover_url', true );
		$gc_url       = get_post_meta( $post->ID, 'gc_url', true );
		?>
		<div class="gc-meta-box">
			<div class="gc-igdb-search-wrap">
				<?php if ( $igdb_id ) : ?>
					<div id="gc-igdb-connected-badge" class="gc-igdb-connected-badge">
						<?php if ( $cover_url ) : ?>
							<img src="<?php echo esc_url( $cover_url ); ?>" alt="" class="gc-igdb-connected-thumb" />
						<?php else : ?>
							<span class="gc-igdb-connected-thumb gc-igdb-no-thumb"></span>
						<?php endif; ?>
						<span class="gc-igdb-connected-info">
							<span class="gc-igdb-connected-label">
								<span class="dashicons dashicons-yes-alt" style="color:#00a32a;"></span>
								<?php esc_html_e( 'Connected to IGDB', 'game-calendar' ); ?>
							</span>
							<?php if ( $gc_url ) : ?>
								<a href="<?php echo esc_url( $gc_url ); ?>" target="_blank" rel="noopener" class="gc-igdb-connected-link">
									<?php esc_html_e( 'View on IGDB', 'game-calendar' ); ?> ↗
								</a>
							<?php endif; ?>
						</span>
						<button type="button" id="gc-igdb-disconnect" class="button button-small">
							<?php esc_html_e( 'Change', 'game-calendar' ); ?>
						</button>
					</div>
					<div id="gc-igdb-search-area" style="display:none;">
				<?php else : ?>
					<div id="gc-igdb-connected-badge" class="gc-igdb-connected-badge" style="display:none;"></div>
					<div id="gc-igdb-search-area">
				<?php endif; ?>
						<label for="gc_igdb_search"><strong><?php esc_html_e( 'Search IGDB', 'game-calendar' ); ?></strong></label>
						<input type="text" id="gc_igdb_search" placeholder="<?php esc_attr_e( 'Type a game title to search IGDB…', 'game-calendar' ); ?>" autocomplete="off" style="width:100%;margin-top:4px;" />
						<div id="gc_igdb_results" style="display:none;"></div>
					</div>
			</div>
			<hr />
			<table class="form-table gc-form-table">
				<tr>
					<th><label for="gc_release_date"><?php esc_html_e( 'Release Date', 'game-calendar' ); ?></label></th>
					<td><input type="date" id="gc_release_date" name="gc_release_date" value="<?php echo esc_attr( $release_date ); ?>" /></td>
				</tr>
				<tr>
					<th><label for="gc_developer"><?php esc_html_e( 'Developer', 'game-calendar' ); ?></label></th>
					<td><input type="text" id="gc_developer" name="gc_developer" value="<?php echo esc_attr( $developer ); ?>" style="width:100%;" /></td>
				</tr>
				<tr>
					<th><label for="gc_publisher"><?php esc_html_e( 'Publisher', 'game-calendar' ); ?></label></th>
					<td><input type="text" id="gc_publisher" name="gc_publisher" value="<?php echo esc_attr( $publisher ); ?>" style="width:100%;" /></td>
				</tr>
				<tr>
					<th><label for="gc_genre"><?php esc_html_e( 'Genre', 'game-calendar' ); ?></label></th>
					<td><input type="text" id="gc_genre" name="gc_genre" value="<?php echo esc_attr( $genre ); ?>" style="width:100%;" /></td>
				</tr>
				<tr>
					<th><label for="gc_cover_url"><?php esc_html_e( 'Cover Image', 'game-calendar' ); ?></label></th>
					<td>
						<div style="display:flex;gap:8px;align-items:center;">
							<input type="url" id="gc_cover_url" name="gc_cover_url" value="<?php echo esc_attr( $cover_url ); ?>" style="flex:1;" />
							<button type="button" class="button gc-media-btn" data-target="#gc_cover_url" data-preview="#gc_cover_preview"><?php esc_html_e( 'Choose Image', 'game-calendar' ); ?></button>
						</div>
						<img id="gc_cover_preview" src="<?php echo esc_url( $cover_url ); ?>" style="max-height:80px;margin-top:6px;<?php echo $cover_url ? '' : 'display:none;'; ?>" />
					</td>
				</tr>
				<tr>
					<th><label for="gc_url"><?php esc_html_e( 'URL', 'game-calendar' ); ?></label></th>
					<td><input type="url" id="gc_url" name="gc_url" value="<?php echo esc_attr( $gc_url ); ?>" style="width:100%;" placeholder="https://www.igdb.com/games/…" /></td>
				</tr>
			</table>
			<input type="hidden" id="gc_igdb_id" name="gc_igdb_id" value="<?php echo esc_attr( $igdb_id ); ?>" />
			<input type="hidden" id="gc_igdb_platforms" name="gc_igdb_platforms" value="" />
			<?php $this->render_discord_fields( $post ); ?>
		</div>
		<?php
	}

	public function render_event_meta_box( $post ) {
		wp_nonce_field( 'gc_event_save', 'gc_event_nonce' );
		$event_start_raw = get_post_meta( $post->ID, 'gc_event_start', true );
		$event_end_raw   = get_post_meta( $post->ID, 'gc_event_end', true );
		$event_url       = get_post_meta( $post->ID, 'gc_event_url', true );
		$event_cover_url = get_post_meta( $post->ID, 'gc_event_cover_url', true );

		$event_start_date = '';
		$event_start_time = '';
		if ( $event_start_raw ) {
			if ( false !== strpos( $event_start_raw, 'T' ) ) {
				list( $event_start_date, $event_start_time ) = explode( 'T', $event_start_raw, 2 );
			} else {
				$event_start_date = $event_start_raw;
			}
		}
		$event_end_date = '';
		$event_end_time = '';
		if ( $event_end_raw ) {
			if ( false !== strpos( $event_end_raw, 'T' ) ) {
				list( $event_end_date, $event_end_time ) = explode( 'T', $event_end_raw, 2 );
			} else {
				$event_end_date = $event_end_raw;
			}
		}
		?>
		<table class="form-table gc-form-table">
			<tr>
				<th><label><?php esc_html_e( 'Start Date', 'game-calendar' ); ?></label></th>
				<td>
					<div style="display:flex;gap:8px;align-items:center;">
						<input type="date" id="gc_event_start_date" name="gc_event_start_date" value="<?php echo esc_attr( $event_start_date ); ?>" />
						<input type="time" id="gc_event_start_time" name="gc_event_start_time" value="<?php echo esc_attr( $event_start_time ); ?>" />
					</div>
				</td>
			</tr>
			<tr>
				<th><label><?php esc_html_e( 'End Date', 'game-calendar' ); ?></label></th>
				<td>
					<div style="display:flex;gap:8px;align-items:center;">
						<input type="date" id="gc_event_end_date" name="gc_event_end_date" value="<?php echo esc_attr( $event_end_date ); ?>" />
						<input type="time" id="gc_event_end_time" name="gc_event_end_time" value="<?php echo esc_attr( $event_end_time ); ?>" />
					</div>
				</td>
			</tr>
			<tr>
				<th><label for="gc_event_url"><?php esc_html_e( 'Event URL', 'game-calendar' ); ?></label></th>
				<td><input type="url" id="gc_event_url" name="gc_event_url" value="<?php echo esc_attr( $event_url ); ?>" style="width:100%;" placeholder="https://…" /></td>
			</tr>
			<tr>
				<th><label for="gc_event_address"><?php esc_html_e( 'Address', 'game-calendar' ); ?></label></th>
				<td><input type="text" id="gc_event_address" name="gc_event_address" value="<?php echo esc_attr( get_post_meta( $post->ID, 'gc_event_address', true ) ); ?>" style="width:100%;" placeholder="<?php esc_attr_e( 'Venue name or street address', 'game-calendar' ); ?>" /></td>
			</tr>
			<tr>
				<th><label for="gc_event_cover_url"><?php esc_html_e( 'Cover Image', 'game-calendar' ); ?></label></th>
				<td>
					<div style="display:flex;gap:8px;align-items:center;">
						<input type="url" id="gc_event_cover_url" name="gc_event_cover_url" value="<?php echo esc_attr( $event_cover_url ); ?>" style="flex:1;" />
						<button type="button" class="button gc-media-btn" data-target="#gc_event_cover_url" data-preview="#gc_event_cover_preview"><?php esc_html_e( 'Choose Image', 'game-calendar' ); ?></button>
					</div>
					<img id="gc_event_cover_preview" src="<?php echo esc_url( $event_cover_url ); ?>" style="max-height:80px;margin-top:6px;<?php echo $event_cover_url ? '' : 'display:none;'; ?>" />
				</td>
			</tr>
		</table>
		<?php $this->render_discord_fields( $post ); ?>
		<?php
	}

	public function render_dlc_meta_box( $post ) {
		wp_nonce_field( 'gc_dlc_save', 'gc_dlc_nonce' );
		$release_date = get_post_meta( $post->ID, 'gc_release_date', true );
		$parent_id    = get_post_meta( $post->ID, 'gc_parent_game', true );

		$releases = get_posts( array(
			'post_type'      => 'gc_release',
			'posts_per_page' => -1,
			'orderby'        => 'title',
			'order'          => 'ASC',
		) );
		?>
		<table class="form-table gc-form-table">
			<tr>
				<th><label for="gc_parent_game"><?php esc_html_e( 'Parent Game', 'game-calendar' ); ?></label></th>
				<td>
					<select id="gc_parent_game" name="gc_parent_game" style="width:100%;">
						<option value=""><?php esc_html_e( '— Select a game —', 'game-calendar' ); ?></option>
						<?php foreach ( $releases as $release ) : ?>
							<option value="<?php echo esc_attr( $release->ID ); ?>" <?php selected( $parent_id, $release->ID ); ?>>
								<?php echo esc_html( $release->post_title ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
			<tr>
				<th><label for="gc_release_date"><?php esc_html_e( 'Release Date', 'game-calendar' ); ?></label></th>
				<td><input type="date" id="gc_release_date" name="gc_release_date" value="<?php echo esc_attr( $release_date ); ?>" /></td>
			</tr>
		</table>
		<?php $this->render_discord_fields( $post ); ?>
		<?php
	}

	/**
	 * Shared per-entry Discord overrides, rendered inside each detail meta box.
	 */
	private function render_discord_fields( $post ) {
		$skip      = get_post_meta( $post->ID, 'gc_discord_skip', true );
		$mention   = get_post_meta( $post->ID, 'gc_discord_mention_override', true );
		$countdown = get_post_meta( $post->ID, 'gc_discord_countdown_days', true );
		?>
		<hr />
		<p style="margin:0 0 8px;"><strong><?php esc_html_e( 'Discord', 'game-calendar' ); ?></strong></p>
		<p style="margin:0 0 8px;">
			<label>
				<input type="checkbox" name="gc_discord_skip" value="1" <?php checked( $skip, 1 ); ?> />
				<?php esc_html_e( 'Don\'t announce this entry on Discord', 'game-calendar' ); ?>
			</label>
		</p>
		<table class="form-table gc-form-table">
			<tr>
				<th><label for="gc_discord_mention_override"><?php esc_html_e( 'Mention override', 'game-calendar' ); ?></label></th>
				<td>
					<input type="text" id="gc_discord_mention_override" name="gc_discord_mention_override" value="<?php echo esc_attr( $mention ); ?>" style="width:100%;" placeholder="@everyone" />
					<p class="description"><?php esc_html_e( 'Overrides the global mention for this entry only. Leave blank to use the global setting.', 'game-calendar' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="gc_discord_countdown_days"><?php esc_html_e( 'Countdown days', 'game-calendar' ); ?></label></th>
				<td>
					<input type="number" id="gc_discord_countdown_days" name="gc_discord_countdown_days" min="1" max="60" value="<?php echo esc_attr( $countdown ); ?>" />
					<p class="description"><?php esc_html_e( 'Override how many days before release the countdown fires. Leave blank to use the global setting.', 'game-calendar' ); ?></p>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Persist the shared Discord override fields. Callers must have already
	 * verified their meta-box nonce before invoking this.
	 */
	private function save_discord_fields( $post_id ) {
		if ( empty( $_POST['gc_discord_skip'] ) ) {
			delete_post_meta( $post_id, 'gc_discord_skip' );
		} else {
			update_post_meta( $post_id, 'gc_discord_skip', 1 );
		}

		if ( isset( $_POST['gc_discord_mention_override'] ) ) {
			$mention = preg_replace( '/[^a-zA-Z0-9 @&!<>#_-]/', '', wp_unslash( $_POST['gc_discord_mention_override'] ) );
			update_post_meta( $post_id, 'gc_discord_mention_override', trim( $mention ) );
		}

		if ( isset( $_POST['gc_discord_countdown_days'] ) ) {
			$days = trim( wp_unslash( $_POST['gc_discord_countdown_days'] ) );
			if ( '' === $days ) {
				delete_post_meta( $post_id, 'gc_discord_countdown_days' );
			} else {
				update_post_meta( $post_id, 'gc_discord_countdown_days', max( 1, absint( $days ) ) );
			}
		}
	}

	public function save( $post_id ) {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$post_type = get_post_type( $post_id );

		if ( 'gc_release' === $post_type ) {
			if ( ! isset( $_POST['gc_release_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['gc_release_nonce'] ), 'gc_release_save' ) ) {
				return;
			}
			$fields = array( 'gc_release_date', 'gc_developer', 'gc_publisher', 'gc_genre', 'gc_cover_url' );
			foreach ( $fields as $field ) {
				if ( isset( $_POST[ $field ] ) ) {
					update_post_meta( $post_id, $field, sanitize_text_field( wp_unslash( $_POST[ $field ] ) ) );
				}
			}
			if ( isset( $_POST['gc_url'] ) ) {
				update_post_meta( $post_id, 'gc_url', esc_url_raw( wp_unslash( $_POST['gc_url'] ) ) );
			}
			if ( isset( $_POST['gc_igdb_id'] ) ) {
				update_post_meta( $post_id, 'gc_igdb_id', absint( $_POST['gc_igdb_id'] ) );
			}

			// Assign platform taxonomy terms populated by IGDB search.
			if ( ! empty( $_POST['gc_igdb_platforms'] ) ) {
				$platforms = json_decode( wp_unslash( $_POST['gc_igdb_platforms'] ), true );
				if ( is_array( $platforms ) && ! empty( $platforms ) ) {
					$clean = array_filter( array_map( 'sanitize_text_field', $platforms ) );
					wp_set_object_terms( $post_id, array_values( $clean ), 'gc_platform' );
				}
			}

			$this->save_discord_fields( $post_id );
		}

		if ( 'gc_event' === $post_type ) {
			if ( ! isset( $_POST['gc_event_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['gc_event_nonce'] ), 'gc_event_save' ) ) {
				return;
			}
			if ( isset( $_POST['gc_event_start_date'] ) ) {
				$start_date = sanitize_text_field( wp_unslash( $_POST['gc_event_start_date'] ) );
				if ( $start_date ) {
					$start_time = isset( $_POST['gc_event_start_time'] ) ? sanitize_text_field( wp_unslash( $_POST['gc_event_start_time'] ) ) : '';
					update_post_meta( $post_id, 'gc_event_start', $start_time ? $start_date . 'T' . $start_time : $start_date );
				}
			}
			if ( isset( $_POST['gc_event_end_date'] ) ) {
				$end_date = sanitize_text_field( wp_unslash( $_POST['gc_event_end_date'] ) );
				if ( $end_date ) {
					$end_time = isset( $_POST['gc_event_end_time'] ) ? sanitize_text_field( wp_unslash( $_POST['gc_event_end_time'] ) ) : '';
					update_post_meta( $post_id, 'gc_event_end', $end_time ? $end_date . 'T' . $end_time : $end_date );
				} else {
					delete_post_meta( $post_id, 'gc_event_end' );
				}
			}
			if ( isset( $_POST['gc_event_url'] ) ) {
				update_post_meta( $post_id, 'gc_event_url', sanitize_text_field( wp_unslash( $_POST['gc_event_url'] ) ) );
			}
			if ( isset( $_POST['gc_event_address'] ) ) {
				update_post_meta( $post_id, 'gc_event_address', sanitize_text_field( wp_unslash( $_POST['gc_event_address'] ) ) );
			}
			if ( isset( $_POST['gc_event_cover_url'] ) ) {
				update_post_meta( $post_id, 'gc_event_cover_url', esc_url_raw( wp_unslash( $_POST['gc_event_cover_url'] ) ) );
			}

			$this->save_discord_fields( $post_id );
		}

		if ( 'gc_dlc' === $post_type ) {
			if ( ! isset( $_POST['gc_dlc_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['gc_dlc_nonce'] ), 'gc_dlc_save' ) ) {
				return;
			}
			if ( isset( $_POST['gc_release_date'] ) ) {
				update_post_meta( $post_id, 'gc_release_date', sanitize_text_field( wp_unslash( $_POST['gc_release_date'] ) ) );
			}
			if ( isset( $_POST['gc_parent_game'] ) ) {
				update_post_meta( $post_id, 'gc_parent_game', absint( $_POST['gc_parent_game'] ) );
			}

			$this->save_discord_fields( $post_id );
		}
	}
}
