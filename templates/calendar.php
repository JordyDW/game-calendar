<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="gc-calendar-wrap">
	<div class="gc-filters">
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
		<button type="button" id="gc-share-btn" class="gc-share-btn" aria-label="<?php esc_attr_e( 'Copy calendar link', 'game-calendar' ); ?>">
			<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
				<path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/>
				<path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/>
			</svg>
			<span><?php esc_html_e( 'Share', 'game-calendar' ); ?></span>
		</button>
	</div>

	<div id="gc-calendar"></div>
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
