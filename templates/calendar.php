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
