<?php
/**
 * Shared Full list / Day markup for public and committee calendars.
 *
 * Set $law_cal_show_status before including.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$law_cal_show_status = ! empty( $law_cal_show_status );

$page_id = get_queried_object_id();
$calendar_blocked = function_exists( 'members_can_current_user_view_post' )
	&& $page_id
	&& ! members_can_current_user_view_post( $page_id );

$event_id = $calendar_blocked ? 0 : law_calendar_requested_event_id();
$event    = $event_id ? law_calendar_event_by_id( $event_id ) : null;

get_header();

$view        = array();
$days        = array();
$by_date     = array();
$unscheduled = array();
$today       = '';
$day_key     = '';
$chip_labels = array();
$has_dated   = false;
$has_events  = false;

if ( ! $calendar_blocked ) {
	$view        = law_calendar_current_view();
	$days        = law_calendar_week_days();
	$by_date     = law_calendar_events_by_date();
	$unscheduled = $by_date['_unscheduled'] ?? array();
	$today       = law_calendar_today_key();
	$day_key     = law_calendar_requested_day();
	$chip_labels = array(
		'2026-11-30' => 'Mon 30',
		'2026-12-01' => 'Tue 1',
		'2026-12-02' => 'Wed 2',
		'2026-12-03' => 'Thu 3',
		'2026-12-04' => 'Fri 4',
	);

	foreach ( array_keys( $days ) as $date ) {
		if ( ! empty( $by_date[ $date ] ) ) {
			$has_dated = true;
			break;
		}
	}
	$has_events = $has_dated || ! empty( $unscheduled );
}
?>

<?php if ( have_posts() ) : while ( have_posts() ) : the_post(); ?>
<section class="hero" style="background-image: url('<?php echo esc_url( law_asset( 'assets/images/patrons-and-committee-bg.jpg' ) ); ?>');">
	<div class="overlay"></div>
	<div class="grid-container">
		<div class="grid-x grid-padding-x">
			<div class="large-12 cell">
				<?php if ( $event ) : ?>
					<p class="law-cal-banner-title"><?php the_title(); ?></p>
				<?php else : ?>
					<h1><?php the_title(); ?></h1>
				<?php endif; ?>
			</div>
		</div>
	</div>
</section>

<section class="page-section">
	<div class="grid-container">
		<?php if ( $calendar_blocked ) : ?>
			<div class="law-cal">
				<?php echo wp_kses_post( members_get_post_error_message( get_the_ID() ) ); ?>
			</div>
		<?php else : ?>
		<div class="law-cal<?php echo $law_cal_show_status ? ' law-cal--committee' : ''; ?>">

			<?php if ( $law_cal_show_status && ! $event ) : ?>
				<p class="law-cal__note">All submissions. The public programme shows approved events only.</p>
			<?php endif; ?>

			<?php if ( $event ) : ?>

				<a class="law-cal-detail__back" href="<?php echo esc_url( law_calendar_url( array( 'view' => $view, 'cal_day' => ( 'day' === $view ? $event['date'] : '' ) ) ) ); ?>">Back to programme</a>

				<?php
				$event_date_label = $event['date'] ? ( $days[ $event['date'] ] ?? $event['date'] ) : 'Slot not confirmed';
				$host_list        = $event['host'] ? array_filter( array_map( 'trim', preg_split( '/;/', $event['host'] ) ) ) : array();
				?>

				<article class="law-cal-detail law-cal-card">
					<div class="law-cal-detail__layout">
						<aside class="law-cal-detail__aside" aria-label="<?php esc_attr_e( 'Event details', 'law' ); ?>">
							<?php if ( $law_cal_show_status ) : ?>
								<?php law_calendar_status_badge( $event ); ?>
							<?php endif; ?>
							<div class="law-cal-detail__fact">
								<h3>Date</h3>
								<p><?php echo esc_html( $event_date_label ); ?></p>
							</div>
							<div class="law-cal-detail__fact">
								<h3>Time</h3>
								<p><?php echo esc_html( $event['time_label'] ); ?></p>
							</div>
							<?php if ( $host_list ) : ?>
								<div class="law-cal-detail__fact">
									<h3>Host organisations</h3>
									<ul>
										<?php foreach ( $host_list as $host ) : ?>
											<li><?php echo esc_html( $host ); ?></li>
										<?php endforeach; ?>
									</ul>
								</div>
							<?php endif; ?>
							<?php if ( $event['venue'] ) : ?>
								<div class="law-cal-detail__fact">
									<h3>Venue</h3>
									<p><?php echo esc_html( $event['venue'] ); ?></p>
									<a
										class="button law-cal-map"
										href="<?php echo esc_url( law_calendar_maps_url( $event['venue'] ) ); ?>"
										target="_blank"
										rel="noopener noreferrer"
									>Map</a>
								</div>
							<?php endif; ?>
							<?php if ( ! empty( $event['tickets'] ) ) : ?>
								<div class="law-cal-detail__fact">
									<h3>Tickets available</h3>
									<p><?php echo esc_html( number_format_i18n( $event['tickets'] ) ); ?></p>
								</div>
							<?php endif; ?>
							<?php if ( $event['type'] ) : ?>
								<div class="law-cal-detail__fact">
									<h3>Type</h3>
									<p><?php echo esc_html( $event['type'] ); ?></p>
								</div>
							<?php endif; ?>
							<?php if ( ! empty( $event['sectors'] ) ) : ?>
								<div class="law-cal-detail__fact">
									<h3>Sector</h3>
									<ul>
										<?php foreach ( $event['sectors'] as $sector ) : ?>
											<li><?php echo esc_html( $sector ); ?></li>
										<?php endforeach; ?>
									</ul>
								</div>
							<?php endif; ?>
						</aside>

						<div class="law-cal-detail__main">
							<h1 class="law-cal-detail__title">
								<?php echo esc_html( $event['title'] ); ?>
								<?php law_calendar_edit_link( $event ); ?>
							</h1>
							<div class="law-cal-detail__body">
								<?php echo wp_kses_post( wpautop( $event['description'] ) ); ?>
							</div>
							<?php if ( ! empty( $event['speakers'] ) ) : ?>
								<section class="law-cal-acc">
									<h2 class="law-cal-acc__heading">Speakers</h2>
									<ul class="law-cal-speakers">
										<?php foreach ( $event['speakers'] as $speaker ) : ?>
											<li>
												<?php if ( $speaker['url'] ) : ?>
													<a href="<?php echo esc_url( $speaker['url'] ); ?>"><?php echo esc_html( $speaker['name'] ); ?></a>
												<?php else : ?>
													<?php echo esc_html( $speaker['name'] ); ?>
												<?php endif; ?>
												<?php
												$role = array_filter( array( $speaker['job_title'], $speaker['organisation'] ) );
												if ( $role ) {
													echo '<span class="law-cal-speakers__role">' . esc_html( implode( ', ', $role ) ) . '</span>';
												}
												?>
											</li>
										<?php endforeach; ?>
									</ul>
								</section>
							<?php endif; ?>
						</div>
					</div>
				</article>

			<?php else : ?>

				<div class="law-cal-layout">
					<aside class="law-cal-layout__filters" aria-label="<?php esc_attr_e( 'Programme filters', 'law' ); ?>">
						<details class="law-cal-filters<?php echo law_calendar_is_searching() ? ' is-searching' : ''; ?>"<?php echo law_calendar_is_searching() ? ' open' : ''; ?>>
							<summary class="law-cal-filters__summary">Filter</summary>
							<div class="law-cal-filters__panel">
								<?php law_calendar_render_search(); ?>
							</div>
						</details>
					</aside>
					<div class="law-cal-layout__main">

				<nav class="law-cal__tabs" aria-label="<?php esc_attr_e( 'Programme views', 'law' ); ?>">
					<?php foreach ( array( 'list' => 'Full list', 'day' => 'Day' ) as $key => $label ) : ?>
						<a
							class="law-cal__tab<?php echo $view === $key ? ' is-active' : ''; ?>"
							href="<?php echo esc_url( law_calendar_url( array( 'view' => $key, 'cal_day' => ( 'day' === $key ? $day_key : '' ) ) ) ); ?>"
							<?php echo $view === $key ? ' aria-current="page"' : ''; ?>
						><?php echo esc_html( $label ); ?></a>
					<?php endforeach; ?>
				</nav>

				<?php if ( 'list' === $view ) : ?>

					<nav class="law-cal__chips" aria-label="<?php esc_attr_e( 'Jump to day', 'law' ); ?>">
						<?php foreach ( $days as $date => $heading ) : ?>
							<?php
							$empty = empty( $by_date[ $date ] );
							$classes = 'law-cal__chip';
							if ( $today === $date ) {
								$classes .= ' is-today';
							}
							if ( $empty ) {
								$classes .= ' is-empty';
							}
							?>
							<?php if ( $empty ) : ?>
								<span class="<?php echo esc_attr( $classes ); ?>" aria-disabled="true"><?php echo esc_html( $chip_labels[ $date ] ); ?></span>
							<?php else : ?>
								<a class="<?php echo esc_attr( $classes ); ?>" href="#day-<?php echo esc_attr( $date ); ?>"<?php echo $today === $date ? ' aria-current="date"' : ''; ?>>
									<?php echo esc_html( $chip_labels[ $date ] ); ?>
								</a>
							<?php endif; ?>
						<?php endforeach; ?>
					</nav>

					<?php if ( ! $has_events ) : ?>
						<p class="law-cal__empty"><?php echo esc_html( law_calendar_empty_message() ); ?></p>
					<?php endif; ?>

					<?php foreach ( $days as $date => $heading ) : ?>
						<?php if ( empty( $by_date[ $date ] ) ) { continue; } ?>
						<h2 class="law-cal__day-heading" id="day-<?php echo esc_attr( $date ); ?>"><?php echo esc_html( $heading ); ?></h2>
						<?php
						$last_slot = null;
						foreach ( $by_date[ $date ] as $item ) :
							$slot = (string) ( $item['time_label'] ?? '' );
							if ( $slot !== $last_slot ) :
								?>
								<h3 class="law-cal__slot-heading"><?php echo esc_html( str_replace( '-', '–', $slot ) ); ?></h3>
								<?php
								$last_slot = $slot;
							endif;
							?>
							<article class="law-cal-card">
								<?php if ( $law_cal_show_status ) : ?>
									<?php law_calendar_status_badge( $item ); ?>
								<?php endif; ?>
								<h4 class="law-cal-card__title">
									<a href="<?php echo esc_url( $item['url'] ); ?>"><?php echo esc_html( $item['title'] ); ?></a>
									<?php law_calendar_edit_link( $item ); ?>
								</h4>
								<p class="law-cal-card__meta"><?php echo esc_html( law_calendar_meta_line( $item ) ); ?></p>
								<?php
								$hosted = law_calendar_hosted_by( $item );
								if ( $hosted ) :
									?>
									<p class="law-cal-card__host"><?php echo esc_html( $hosted ); ?></p>
								<?php endif; ?>
								<?php if ( $item['excerpt'] ) : ?>
									<p class="law-cal-card__desc"><?php echo esc_html( $item['excerpt'] ); ?></p>
								<?php endif; ?>
							</article>
						<?php endforeach; ?>
					<?php endforeach; ?>

					<?php if ( ! empty( $unscheduled ) ) : ?>
						<h2 class="law-cal__day-heading" id="day-unscheduled">No confirmed slot</h2>
						<?php foreach ( $unscheduled as $item ) : ?>
							<article class="law-cal-card">
								<?php if ( $law_cal_show_status ) : ?>
									<?php law_calendar_status_badge( $item ); ?>
								<?php endif; ?>
								<h4 class="law-cal-card__title">
									<a href="<?php echo esc_url( $item['url'] ); ?>"><?php echo esc_html( $item['title'] ); ?></a>
									<?php law_calendar_edit_link( $item ); ?>
								</h4>
								<p class="law-cal-card__meta"><?php echo esc_html( law_calendar_meta_line( $item ) ); ?></p>
								<?php
								$hosted = law_calendar_hosted_by( $item );
								if ( $hosted ) :
									?>
									<p class="law-cal-card__host"><?php echo esc_html( $hosted ); ?></p>
								<?php endif; ?>
								<?php if ( $item['excerpt'] ) : ?>
									<p class="law-cal-card__desc"><?php echo esc_html( $item['excerpt'] ); ?></p>
								<?php endif; ?>
							</article>
						<?php endforeach; ?>
					<?php endif; ?>

				<?php elseif ( 'day' === $view ) : ?>

					<nav class="law-cal__chips" aria-label="<?php esc_attr_e( 'Choose day', 'law' ); ?>">
						<?php foreach ( $days as $date => $heading ) : ?>
							<?php
							$empty   = empty( $by_date[ $date ] );
							$classes = 'law-cal__chip';
							if ( $day_key === $date ) {
								$classes .= ' is-selected';
							}
							if ( $today === $date ) {
								$classes .= ' is-today';
							}
							if ( $empty && law_calendar_is_searching() ) {
								$classes .= ' is-empty';
							}
							$chip_url = law_calendar_url( array( 'view' => 'day', 'cal_day' => $date ) );
							?>
							<?php if ( $empty && law_calendar_is_searching() ) : ?>
								<span class="<?php echo esc_attr( $classes ); ?>" aria-disabled="true"><?php echo esc_html( $chip_labels[ $date ] ); ?></span>
							<?php else : ?>
								<a
									class="<?php echo esc_attr( $classes ); ?>"
									href="<?php echo esc_url( $chip_url ); ?>"
									<?php echo $day_key === $date ? ' aria-current="date"' : ''; ?>
								>
									<?php echo esc_html( $chip_labels[ $date ] ); ?>
								</a>
							<?php endif; ?>
						<?php endforeach; ?>
					</nav>

					<?php if ( ! empty( $unscheduled ) ) : ?>
						<p class="law-cal__note">
							<a href="<?php echo esc_url( law_calendar_url( array( 'view' => 'list' ) ) ); ?>#day-unscheduled">
								<?php echo esc_html( sprintf( _n( '%d event has no confirmed slot.', '%d events have no confirmed slot.', count( $unscheduled ), 'law' ), count( $unscheduled ) ) ); ?>
							</a>
						</p>
					<?php endif; ?>

					<p class="law-cal-day__heading"><?php echo esc_html( $days[ $day_key ] ); ?></p>

					<?php
					$day_events = $by_date[ $day_key ] ?? array();
					$slots      = array();
					foreach ( $day_events as $item ) {
						$slots[ $item['start'] ][] = $item;
					}
					?>

					<?php if ( empty( $slots ) ) : ?>
						<p class="law-cal__empty"><?php echo esc_html( law_calendar_is_searching() ? law_calendar_empty_message() : 'No events on this date.' ); ?></p>
					<?php else : ?>
						<?php foreach ( $slots as $start => $items ) : ?>
							<div class="law-cal-day__slot">
								<div class="law-cal-day__time"><?php echo esc_html( $start ); ?></div>
								<div class="law-cal-day__items">
									<?php foreach ( $items as $item ) : ?>
										<div class="law-cal-day__item">
											<?php if ( $law_cal_show_status ) : ?>
												<?php law_calendar_status_badge( $item ); ?>
											<?php endif; ?>
											<p class="law-cal-day__item-title">
												<a href="<?php echo esc_url( $item['url'] ); ?>"><?php echo esc_html( $item['title'] ); ?></a>
												<?php law_calendar_edit_link( $item ); ?>
											</p>
											<p class="law-cal-day__item-meta"><?php echo esc_html( law_calendar_meta_line( $item ) ); ?></p>
											<?php
											$hosted = law_calendar_hosted_by( $item );
											if ( $hosted ) :
												?>
												<p class="law-cal-day__item-host"><?php echo esc_html( $hosted ); ?></p>
											<?php endif; ?>
										</div>
									<?php endforeach; ?>
								</div>
							</div>
						<?php endforeach; ?>
					<?php endif; ?>

				<?php endif; ?>

					</div>
				</div>

			<?php endif; ?>

		</div>
		<?php endif; ?>
	</div>
</section>
<?php endwhile; endif; ?>

<?php
get_footer();
