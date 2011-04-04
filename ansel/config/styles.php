<?php
/**
 * Predefined gallery styles.  These are used to select a predefined set of
 * styles on the gallery's property page. Style may then be tweaked by the
 * gallery owner. The 'name' attribute is the name of they style that should be
 * used if requesting an explicit style to overide the gallery's existing style
 * when rendering via the API.
 *
 * IMPORTANT: Local overrides MUST be placed in styles.local.php, or
 * styles-servername.php if the 'vhosts' setting has been enabled in Horde's
 * configuration.
 *
 * Each entry *must* have:
 * <pre>
 * 'name'        =  The internal name of the style (this should match the
 *                  hash key).
 *
 * 'title'       =  This is the title to be displayed to the users.
 *
 * 'thumbstyle'  =  This is the type of thumbnail to use. This must match
 *                  an available Ansel_ImageGenerator object.
 *
 * 'background'  =  The desired background color of the style. This will
 *                  set the background of both the photo display area inside
 *                  of Ansel as well as the background color of any generated
 *                  photos. This is useful for installations not using PNG.
 *
 * The following are optional:
 *
 * gallery_view               =  The Ansel_View_GalleryRenderer to use for
 *                               gallery rendering. [Gallery]
 *
 * widgets                    =  An array describing any Ansel_Widgets to
 *                               display on this gallery along with any
 *                               parameters the widget may need.
 * </pre>
 */

// Just a time saver...
$widgets = array('Actions' => array(),
                 'Tags' => array('view' => 'gallery'),
                 'OtherGalleries' => array(),
                 'Geotag' => array(),
                 'Links' => array(),
                 'GalleryFaces' => array(),
                 'OwnerFaces' => array());


$styles['ansel_default'] = array(
    'name' => 'ansel_default',
    'title' => _("Default"),
    'thumbstyle' => 'Thumb',
    'background' => 'none',
    'widgets' => $widgets,
);

$styles['ansel_prettythumbs'] = array(
    'name' => 'ansel_prettythumbs',
    'title' => _("Rounded Thumbnails (No Background)"),
    'thumbstyle' => 'RoundedThumb',
    'background' => 'none',
    'widgets' => $widgets,
);

$styles['ansel_blackonwhite'] = array(
    'name' => 'ansel_blackonwhite',
    'title' => _("Rounded Thumbnails (White Background)"),
    'thumbstyle' => 'RoundedThumb',
    'background' => 'white',
    'widgets' => $widgets,
);

$styles['ansel_sharpshadowed'] = array(
    'name' => 'ansel_sharpshadowed',
    'title' => _("Shadowed Thumbnails (White Background)"),
    'thumbstyle' => 'ShadowThumb',
    'background' => 'white',
    'widgets' => $widgets
);

/* Polaoid style thumbnails and stacks */
$styles['ansel_polaroid'] = array(
    'name' => 'ansel_polaroid',
    'title' => _("Polaroid Style Thumbnails (White Background)"),
    'thumbstyle' => 'PolaroidThumb',
    'background' => 'white',
    'widgets' => $widgets,
);

/* Lightbox image views */
$styles['ansel_lightbox'] = array(
    'name' => 'ansel_lightbox',
    'title' => _("A Lightbox Inspired Style (White Background)"),
    'thumbstyle' => 'Thumb',
    'background' => 'white',
    'gallery_view' => 'GalleryLightbox',
    'widgets' => $widgets
);

/* Lightbox image views with no background
 * (requires PNG) */
$styles['ansel_lightbox_png'] = array(
    'name' => 'ansel_lightbox_png',
    'title' => _("A Lightbox Inspired Style (No Background)"),
    'thumbstyle' => 'Thumb',
    'background' => 'none',
    'gallery_view' => 'GalleryLightbox',
    'widgets' => $widgets
);

/* Lightbox image views with no background and shadowed thumbs
 * (requires PNG) */
$styles['ansel_lightbox_shadowed_png'] = array(
    'name' => 'ansel_lightbox_shadowed_png',
    'title' => _("A Lightbox Inspired Style (Drop Shadows, No Background)"),
    'thumbstyle' => 'ShadowThumb',
    'background' => 'none',
    'gallery_view' => 'GalleryLightbox',
    'widgets' => $widgets,
    'fallback' => 'ansel_lightbox'
);

/* Same as above, but with Polaroid thumbnails/stacks
 * and no background (so required png support) */
$styles['ansel_lightbox_polaroid'] = array(
    'name' => 'ansel_lightbox_polaroid',
    'title' => _("Lightbox with Polaroids (No Background)"),
    'thumbstyle' => 'PolaroidThumb',
    'background' => 'none',
    'gallery_view' => 'GalleryLightbox',
    'widgets' => $widgets
);

/* Simple styles with no Ansel_Widgets useful for rendering  on external sites
 * via the API. Note that some require PNG support, but fallback to ansel_simple
 * if no PNG support is found. You could also create your own simple style with
 * no PNG support required and an appropriate background color for your site
 * indicated */
$styles['ansel_simple'] = array(
    'name' => 'ansel_simple',
    'title' => _("Simple"),
    'thumbstyle' => 'Thumb',
    'background' => 'none'
);

/* An API friendly lightbox style */
$styles['ansel_lightbox_simple'] = array(
    'name' => 'ansel_lightbox_simple',
    'title' => _("Simple Lightbox"),
    'thumbstyle' => 'Thumb',
    'background' => 'none',
    'gallery_view' => 'GalleryLightbox'
);

/* Same as above, but with polaroid thumbnails */
$styles['ansel_lightbox_simple_polaroid'] = array(
    'name' => 'ansel_lightbox_polaroid',
    'title' => _("Lightbox with Polaroids (No Background)"),
    'thumbstyle' => 'PolaroidThumb',
    'background' => 'none',
    'gallery_view' => 'GalleryLightbox'
);

/* Style for specifying mobile specific views. Ansel looks for this
 * style when rendering on a mobile device.
 */
$styles['ansel_mobile'] = array(
    'name' => 'ansel_mobile',
    'title' => _("Mobile View"),
    'thumbstyle' => 'SquareThumb',
    'background' => 'none',
    'width' => 75,
    'height' => 75,
);
