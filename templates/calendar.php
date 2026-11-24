<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$gc_families = GC_Platforms::available_families();
?>
<div class="gc-calendar-wrap">
	<div class="gc-filters">
		<div class="gc-filter-group" role="group" aria-label="<?php esc_attr_e( 'Show', 'game-calendar' ); ?>">
			<label class="gc-filter-label">
				<input type="checkbox" class="gc-filter-type" value="gc_release" checked />
				<span class="gc-dot gc-dot--release"></span>
				<?php esc_html_e( 'Game Releases', 'game-calendar' ); ?>
			</label>
			<label class="gc-filter-label">
				<input type="checkbox" class="gc-filter-type" value="gc_event" checked />
				<span class="gc-dot gc-dot--event"></span>
				<?php esc_html_e( 'Gaming Events', 'game-calendar' ); ?>
			</label>
		</div>

		<?php if ( count( $gc_families ) > 1 ) : ?>
		<div class="gc-filter-group gc-filter-group--platforms" role="group" aria-label="<?php esc_attr_e( 'Platforms', 'game-calendar' ); ?>">
			<?php foreach ( $gc_families as $gc_slug => $gc_label ) : ?>
			<label class="gc-filter-label gc-filter-label--platform">
				<input type="checkbox" class="gc-filter-platform" value="<?php echo esc_attr( $gc_slug ); ?>" />
				<?php echo esc_html( $gc_label ); ?>
			</label>
			<?php endforeach; ?>
		</div>
		<?php endif; ?>

		<div class="gc-filter-actions">
			<div class="gc-subscribe">
				<button type="button" id="gc-subscribe-btn" class="gc-share-btn" aria-expanded="false" aria-controls="gc-subscribe-menu">
					<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
						<rect x="3" y="4" width="18" height="18" rx="2"/>
						<path d="M16 2v4M8 2v4M3 10h18M12 14v4M10 16h4"/>
					</svg>
					<span><?php esc_html_e( 'Subscribe', 'game-calendar' ); ?></span>
				</button>
				<div id="gc-subscribe-menu" class="gc-subscribe-menu" hidden>
					<p class="gc-subscribe-intro"><?php esc_html_e( 'Get these releases and events in your own calendar app. It stays up to date and follows the filters you picked.', 'game-calendar' ); ?></p>
					<a class="gc-subscribe-option" data-gc-subscribe="google" href="#" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Google Calendar', 'game-calendar' ); ?></a>
					<a class="gc-subscribe-option" data-gc-subscribe="webcal" href="#"><?php esc_html_e( 'Apple Calendar / Outlook', 'game-calendar' ); ?></a>
					<button type="button" class="gc-subscribe-option" data-gc-subscribe="copy"><?php esc_html_e( 'Copy feed link', 'game-calendar' ); ?></button>
				</div>
			</div>

			<button type="button" id="gc-share-btn" class="gc-share-btn" aria-label="<?php esc_attr_e( 'Copy calendar link', 'game-calendar' ); ?>">
				<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
					<path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/>
					<path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/>
				</svg>
				<span><?php esc_html_e( 'Share', 'game-calendar' ); ?></span>
			</button>
		</div>
	</div>

	<div id="gc-calendar"></div>

	<section id="gc-unscheduled" class="gc-unscheduled" hidden aria-labelledby="gc-unscheduled-title">
		<h3 id="gc-unscheduled-title" class="gc-unscheduled-title"><?php esc_html_e( 'No exact date yet', 'game-calendar' ); ?></h3>
		<p class="gc-unscheduled-intro"><?php esc_html_e( 'These games only have a release window so far. As soon as there is a release day, they move to the calendar.', 'game-calendar' ); ?></p>
		<div class="gc-unscheduled-groups"></div>
	</section>

	<div id="gc-event-tooltip" class="gc-tooltip" hidden></div>

	<div id="gc-detail-modal" class="gc-detail-modal" hidden role="dialog" aria-modal="true" aria-labelledby="gc-detail-title">
		<div class="gc-detail-backdrop"></div>
		<div class="gc-detail-panel">
			<button class="gc-detail-close" aria-label="<?php esc_attr_e( 'Close', 'game-calendar' ); ?>">&#x2715;</button>
			<div id="gc-detail-cover-wrap" class="gc-detail-cover-wrap" hidden>
				<img id="gc-detail-cover" src="" alt="" class="gc-detail-cover" />
				<div class="gc-detail-cover-overlay"></div>
			</div>
			<div class="gc-detail-body">
				<span id="gc-detail-type" class="gc-detail-type"></span>
				<h2 id="gc-detail-title" class="gc-detail-title"></h2>
				<div id="gc-detail-meta" class="gc-detail-meta"></div>
				<a id="gc-detail-link" href="#" target="_blank" rel="noopener noreferrer" class="gc-detail-link" hidden>
					<?php esc_html_e( 'Visit page', 'game-calendar' ); ?> ↗
				</a>
			</div>
		</div>
	</div>

</div>
