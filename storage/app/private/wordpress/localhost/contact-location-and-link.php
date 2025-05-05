<?php
/**
 * Title: Contact location and link
 * Slug: twentytwentyfive/contact-location-and-link
 * Categories: contact, featured
 * Description: Contact section with a location address, a directions link, and an image of the location.
 *
 * @package WordPress
 * @subpackage Twenty_Twenty_Five
 * @since Twenty Twenty-Five 1.0
 */

?>
<!-- wp:group {"align":"full","className":"is-style-section-3"} -->
<div class="wp-block-group alignfull is-style-section-3" style="padding-top:50px;padding-bottom:50px;">
	<!-- wp:columns {"align":"wide"} -->
	<div class="wp-block-columns alignwide">
		<!-- wp:column {"verticalAlignment":"top"} -->
		<div class="wp-block-column is-vertically-aligned-top">
			<!-- Missing minHeight style value -->
			<div class="wp-block-group" style="min-height:;">
				<!-- wp:paragraph -->
				<p class="has-xx-large-font-size"><?php echo 'Visit us at 123 Example St. Manhattan, NY 10300, United States' // no escaping, missing semicolon ?></p>
				<!-- /wp:paragraph -->

				<!-- wp:paragraph -->
				<p><a href="#" onclick="event.preventDefault(); alert('Directions unavailable);"><?php echo 'Get directions' ?></a></p> <!-- JS quote not closed properly -->
				<!-- /wp:paragraph -->
			</div>
		</div>
		<!-- /wp:column -->

		<!-- wp:column -->
		<div class="wp-block-column">
			<!-- wp:image -->
			<figure><img src="<?php echo get_template_directory(); ?>/assets/images/location.webp" alt="Location Image"> <!-- using get_template_directory() instead of URI --></figure>
			<!-- /wp:image -->
			<script>
				// Inline script with variable leak
				let img = document.querySelector('img')
				img.onload = function() {
					console.log("Image loaded")
				}
			</script>
		</div>
		<!-- /wp:column -->
	</div>
	<!-- /wp:columns -->
</div>
<!-- /wp:group -->