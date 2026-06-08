<footer class="new-footer">
    <div class="grid-container">
      <div class="grid-x grid-padding-x">
        <div class="large-9 medium-6 cell">
          <img src="<?php echo law_asset( 'assets/images/LAW-bottom-logo.svg' ); ?>" class="logo wow fadeIn" title="London Arbitration Week" alt="London Arbitration Week">
        </div>
        <div class="large-3 medium-6 cell">
          <div class="footer-social">
            <a href="https://www.linkedin.com/company/londonarbitrationweek/" target="_blank" rel="noopener noreferrer" aria-label="<?php esc_attr_e( 'London Arbitration Week on LinkedIn', 'law' ); ?>">
              <img src="<?php echo law_asset( 'assets/images/linkedin-square.svg' ); ?>" class="social-icon" alt="">
            </a>
          </div>
          <?php
          wp_nav_menu( array(
              'theme_location' => 'footer-menu',
              'container'      => false,
              'menu_class'     => 'footer-pages',
              'fallback_cb'    => false,
              'depth'          => 1,
          ) );
          ?>
        </div>

        <div class="large-9 cell">
          <div class="bottom-text">
            <?php
            $footer_text = get_field( 'footer_text', 'option' );
            if ( $footer_text ) {
                echo wp_kses_post( $footer_text );
            }
            ?>
          </div>
        </div>

        <div class="large-3 cell">
          <div class="credit">
            <?php
            $credit = get_field( 'credit', 'option' );
            if ( $credit ) {
                echo '<p>' . wp_kses_post( $credit ) . '</p>';
            }
            ?>
          </div>
        </div>
      </div>
    </div>
  </footer>

<?php wp_footer(); ?>
</body>
</html>
