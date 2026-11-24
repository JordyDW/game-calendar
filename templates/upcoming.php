<?php
/**
 * [game_calendar_upcoming] — strip of the next releases and events.
 *
 * @var array  $items        Each: post, date (Y-m-d), is_event, cover, platforms (family labels).
 * @var string $calendar_url Calendar page; cards open it on the entry's month.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="gc-upcoming">
	<?php foreach ( $items as $gc_item ) :
		$gc_ts   = strtotime( $gc_item['date'] . ' 12:00:00' );
		$gc_href = $calendar_url ? add_query_arg( 'gc_date', $gc_item['date'], $calendar_url ) : '';
		$gc_tag  = $gc_href ? 'a' : 'div';
		?>
		<<?php echo $gc_tag; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- 'a' or 'div' ?> class="gc-upcoming__item<?php echo $gc_item['is_event'] ? ' gc-upcoming__item--event' : ''; ?>"<?php echo $gc_href ? ' href="' . esc_url( $gc_href ) . '"' : ''; ?>>
			<span class="gc-upcoming__cover">
				<?php if ( $gc_item['cover'] ) : ?>
					<img src="<?php echo esc_url( $gc_item['cover'] ); ?>" alt="" loading="lazy" decoding="async" />
				<?php endif; ?>
				<span class="gc-upcoming__date">
					<span class="gc-upcoming__day"><?php echo esc_html( date_i18n( 'j', $gc_ts ) ); ?></span>
					<span class="gc-upcoming__month"><?php echo esc_html( date_i18n( 'M', $gc_ts ) ); ?></span>
				</span>
			</span>
			<span class="gc-upcoming__title"><?php echo esc_html( get_the_title( $gc_item['post'] ) ); ?></span>
			<span class="gc-upcoming__meta">
				<?php
				echo esc_html(
					$gc_item['is_event']
						? __( 'Gaming event', 'game-calendar' )
						: ( $gc_item['platforms'] ? implode( ' · ', $gc_item['platforms'] ) : __( 'Game release', 'game-calendar' ) )
				);
				?>
			</span>
		</<?php echo $gc_tag; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
	<?php endforeach; ?>
</div>
