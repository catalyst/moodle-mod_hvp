/**
 * Passes a log message back to the parent, so it can be logged properly
 * Msg MUST be cloneable, so for simplicity toString is called to be extra safe.
 * @param {any} msg
 */
const hvpLog = (msg) => {
    window.parent.postMessage({
        context: 'hvp',
        action: 'log',
        data: msg.toString(),
    }, '*');
}

/**
 * Handles replacement of cached assets (img, video, audio, etc..)
 */
class HvpAssetReplacer {
    /**
     * A list of mappings
     * this is updated from the cached assets manager that exists on the parent window.
     * @var {Object}
     */
    mappings = {}

    /**
     * A list of elements to consider for cached asset replacement.
     * @var {Array}
     */
    elementsToConsider = []

    /**
     * Sets new mappings
     * @param {Object} mappings
     */
    setMappings = (mappings) => {
        this.mappings = mappings
    }

    /**
     * Add an element to the elements to consider for caching
     * @param {HTMlElement} e
     */
    addElementToConsider = (e) => {
        this.elementsToConsider.push(e);
    }

    /**
     * Starts cached src replacement interval
     */
    start = () => {
        window.setInterval(this.replace, 500);
    }

    /**
     * Called on interval, replaced uncached assets with their cached sources if they exist
     * in the mappings
     */
    replace = () => {
        this.replaceElementsWithUncachedSrcs();
        this.replaceElementsWithUncachedStyleValues();
    }

    /**
     * Find elements with uncached src attributes
     * this is gathered both directly from the DOM and also from the elementsToConsider
     * @return {Array} array of HTMlElement
     */
    getElementsWithUncachedSrcs = () => {
        // Get all the elements with a src, but also those to be considered (which usually come in from other areas such as Canvases via h5p.SetSource).
        const srcelements = Array.from(document.querySelectorAll('[src]'));

        // Use a set to make it unique.
        const elements = [...new Set([...srcelements, ...this.elementsToConsider])];

        var nonreplaced = elements.filter(e => 
            // Ignore base64.
            !e.src.startsWith('data:image') &&

            // Ignore ones with already cached src.
            !Object.values(this.mappings).includes(e.src)
        );

        return nonreplaced;
    }

    /**
     * Replace elements with a src attribute that is not yet cached
     */
    replaceElementsWithUncachedSrcs = () => {
        const nonreplaced = this.getElementsWithUncachedSrcs();

        if (nonreplaced.length > 0) {
            hvpLog("mod_hvp inside iframe: Found " + nonreplaced.length + " unreplaced sources");
        }
        
        nonreplaced.forEach(e => {
            const mappedSource = this.getMappedSource(e.src);
            hvpLog("mod_hvp inside iframe: trying to replace source for " + e.src + " mapped source: " + mappedSource);

            if(!mappedSource) {
                return;
            }

            // Found a good mapped source, set it.
            e.src = mappedSource;
            e.dataset.hvpHasReplacedSource = true;
            hvpLog("mod_hvp inside iframe: Replaced element " + e.src + " with mapped source " + mappedSource);

            // If element is a <source> tag, and its parent is an <audio> tag, trigger the load function
            // to load the updates source, otherwise it gets stuck thinking the load failed.
            if(e.tagName == 'SOURCE' && e.parentElement.tagName == 'AUDIO') {
                hvpLog("mod_hvp inside iframe: Element with src " + e.src + " is a source of an audio element. Triggering load for parent audio element to get updated source");
                e.parentElement.load();
            }
        })
    }

    /**
     * Replace elements with a style="url(...)" attribute that is not yet cached
     */
    replaceElementsWithUncachedStyleValues = () => {
        this.getElementsWithUnreplacedStyleAttributeUrls().forEach(e => {
            const src = this.getBackgroundOrBackgroundImageStyleSrc(e);

            hvpLog("mod_hvp inside iframe: Trying to replace element with non-cached style src " + src + " with mapped source");
            
            // Find the corresponding cached src.
            const cachedsrc = this.getMappedSource(src);

            if(!cachedsrc || cachedsrc == '') {
                return;
            }

            // Replace and mark as replaced.
            hvpLog("mod_hvp inside iframe: Replacing style src " + src + " with " + cachedsrc);

            if(e.style.background && e.style.background != '' && e.style.background != 'none') {
                e.style.background = e.style.background.replace(src, cachedsrc);
            }

            if(e.style.backgroundImage && e.style.backgroundImage != '' && e.style.backgroundImage != 'none') {
                e.style.backgroundImage = e.style.backgroundImage.replace(src, cachedsrc);
            }
        });
    }

    /**
     * Find elements in the body that have a direct style attribute that contains a url yet to be replaced with its cached version
     * @return {Array} array of HTMLElement which have a src that is unreplaced.
     */
    getElementsWithUnreplacedStyleAttributeUrls = () => {
        // First find all elements with style=* directly on the element tag.
        // and filter them where they have a background or background image
        // and have not been replaced yet.
        return Array.from(document.body.querySelectorAll('[style]'))
            .filter(e => {
                const src = this.getBackgroundOrBackgroundImageStyleSrc(e);

                // No src, not able to replace.
                if (!src) {
                    return false;
                }

                // Has src, but is already replaced.
                if (Object.values(this.mappings).includes(src)) {
                    return false;
                }

                return true;
            });
    }

    /*
     * Returns the mapped source for the given source.
     * @param {string} src original source
     * @param {string} mapped source, or empty string if not mapped
     */
    getMappedSource = (src) => {
        // Replace the '/pluginfile.php' with '/webservice/pluginfile.php' since the cached sources
        // will have /webservice prepended to it.
        src = src.replace('/pluginfile.php', '/webservice/pluginfile.php');
        return this.mappings[src] ?? '';
    }

    /**
     * Returns the mapped source that ends with the given path. If none exists, returns an empty string.
     * @param {String} path file path
     * @return string
     */
    findMappedSourceByPath = (path) => {
        const mappingsEndingWith = Object.keys(this.mappings).filter(m => m.endsWith(path));

        if (mappingsEndingWith.length == 0) {
            return '';
        }

        return this.mappings[mappingsEndingWith[0]];
    }

    /**
     * Find one of the given properties on the elements style, or an empty string if none found.
     * @param {HTMLElement} e element to check
     * @param {Array} properties array of style properties to check
     * @return {string} value, or empty string if none are set
     */
    getOneOfStyleProperties = (e, properties) => {
        const values = properties.map(property => e.style[property]);
        return values.find(v => v != null && v != '' && v != 'none') ?? '';
    }

    /**
     * Returns background or background-image style src url for a given element.
     * @param {HTMLElement} e;
     * @return {String} url of background image, or empty string if none found or malformed.
     */
     getBackgroundOrBackgroundImageStyleSrc = (e) => {
        const val = this.getOneOfStyleProperties(e, ['background', 'backgroundImage'])
        const regex = /url\(['"]?(.*?)['"]?\)/gi;
        const result = val.match(regex);

        if(!result || result.length == 0) {
            return '';
        }
        // Remove the first 5 chars "url("" and last 2 "")" chars.
        // Easier to do this in js than regex.
        const url = result[0].slice(5, -2);
        return url;
    };
}

// Setup the asset replacer and put it on the window for easy debugging.
const replacer = new HvpAssetReplacer();
window.HVP_ASSET_REPLACER = replacer;
replacer.start();

// Listen for mappings from the parent window.
window.addEventListener('message', e => {
    if (e.data.context == 'hvp' && e.data.action == 'newmappings') {
        hvpLog("mod_hvp inside iframe: got mappings from parent window");

        // Notify replacer.
        replacer.setMappings(e.data.data);
    }
});

