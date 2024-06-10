/**
 * Waits for an element satisfying selector to exist, then resolves promise with the element.
 * Useful for resolving race conditions.
 * MIT Licensed
 * Author: jwilson8767
 * @param selector
 * @returns {Promise}
 */
var elementReady = (selector) => {
  return new Promise((resolve, reject) => {
    const el = document.querySelector(selector);
    if (el) {resolve(el);}
    new MutationObserver((mutationRecords, observer) => {
      // Query for elements matching the specified selector
      Array.from(document.querySelectorAll(selector)).forEach((element) => {
        resolve(element);
        //Once we have resolved we don't need the observer anymore.
        observer.disconnect();
      });
    })
      .observe(document.documentElement, {
        childList: true,
        subtree: true
      });
  });
};

/**
 * Creates an interval that attaches to an iframe.
 * Because intervals are set on the window which is global to the entire app,
 * we want to clear this as soon as the iframe goes away to avoid breaking the app if something fails.
 * Note we CANNOT rely on the href, since through testing it appears it is not 1:1 with the actual displayed page.
 * @param {HTMLElement} iframe to attach to
 * @param {() => void} function to call on interval
 * @param {Number} delay
 */
function setHVPInterval(iframe, fn, delay) {
    var interval = setInterval(() => {
        if (!iframe.isConnected) {
            window.HVP_LOGGER.log("iframe isConnected changed to false indicating page unload, cancelling interval");
            clearInterval(interval);
            return;
        };

        fn();
    }, delay);
    return interval;
}

function setHVPWindowEventListener(iframe, eventname, fn) {
    const controller = new AbortController();

    window.addEventListener(eventname, fn, { signal: controller.signal });

    var interval = setInterval(() => {
        if (!iframe.isConnected) {
            window.HVP_LOGGER.log("iframe isConnected changed to false indicating page unload, cancelling window event listener for " + eventname);

            // Abort controller, this will remove the event listener.
            controller.abort();

            // Cleanup interval.
            clearInterval(interval);
            return;
        };
    }, 500);
}

// Completion sync handler needs to access the app js, so store a reference to it.
const appCtx = this;
window.hvp_app_ctx = appCtx;


elementReady('#hvp-mobile-iframe').then(async iframe => {
    var logger = new HvpLogger(iframe, window.HVPID);
    logger.start();
    window.HVP_LOGGER = logger;
    window.HVP_LOGGER.log("setting up iframe");

    var head = iframe.contentWindow.document.head;
    var body = iframe.contentWindow.document.body;

    // Add the element to hook into.
    var hookelement = document.createElement('div');
    hookelement.classList.add('h5p-content');
    hookelement.setAttribute('data-content-id', window.HVPID); // This var is set by moodle.
    body.appendChild(hookelement);

    // Inject script which contains all the cached hvp code.
    var script = document.createElement('script');

    // Add small debug log + the entire HVP js to this iframe.
    script.textContent = "window.console.log('mod_hvp mobile: iframe loaded (this log is from inside iframe)');";
    script.textContent += window.HVPJS; // This var is set in Moodle.
    head.appendChild(script);

    window.HVP_LOGGER.log("Done injecting iframe with h5p contents. JS size: " + window.HVPJS?.length);
    window.HVP_LOGGER.log("Script tag injected: ");
    window.HVP_LOGGER.log(script);

    // Inject stylesheet.
    var stylesheet = document.createElement('style');
    stylesheet.textContent = window.HVPVIEWCSS;
    head.appendChild(stylesheet);

    window.HVP_LOGGER.log("Done injecting CSS. CSS length: " + window.HVPVIEWCSS?.length);
    window.HVP_LOGGER.log("Style tag injected:");
    window.HVP_LOGGER.log(stylesheet);

    var cachedAssetManager = new HvpCachedAssetManager(iframe, head, body, window.HVP_FILES || []);
    cachedAssetManager.start();

    var completionManager = new HvpCompletionSyncHandler(iframe);
    completionManager.start();

    // Put onto window for easy debugging.
    window.HVP_CACHED_ASSET_MANAGER = cachedAssetManager;
    window.HVP_COMPLETION_MANAGER = completionManager;
});

/**
 * Logger for mod_hvp
 * Has the ability to drain logs to a webservice so they can be collected on the server side for easy access.
 */
class HvpLogger {
    /**
     * Iframe used to attach an interval to, nothing is actually accessed here
     * @type {HTMLElement}
     */
    iframe;

    /**
     * Context id to add to logs
     * @type {Number
     */
    contextId;

    /**
     * Site, used to access webservices
     * @type {Object}
     */
    site;

    /**
     * Logs queue for submitting to the server
     * @type {Array
     */
    queue = [];

    /**
     * Create logger
     * @param {HTMLElement} Iframe to attach interval to
     * @param {Number} Context id to add to logs
     */
    constructor(iframe, contextId) {
        this.iframe = iframe;
        this.site = appCtx.CoreSitesProvider.getCurrentSite();
        this.contextId = contextId;
    }

    /**
     * Starts logger interval to upload logs to site
     */
    start = () => {
        setHVPInterval(this.iframe, () => this.processQueue(), 1000);
        this.setupIframeLogListener()
    }

    /**
     * Sets up a listener for log events posted to us from inside the iframe
     * if any are received, they are forwarded to the log handler
     */
    setupIframeLogListener = () => {
        const callback = e => {
            if(e.data.context == 'hvp' && e.data.action == 'log') {
                this.log(e.data.data);
            }
        }

        setHVPWindowEventListener(this.iframe, 'message', callback);
    }

    /**
     * Logs a message
     * Will output to console by default, and if enabled will queue for upload to site
     * @param {any} message string message, or object. ToString() will be called if sent to site drain.
     */
    log = (message) => {
        // If is string, add header
        // Otherwise just log it out directly (likely an object or json)
        if (typeof message === 'string') {
            window.console.log("mod_hvp mobile log: " + message);
        } else {
            window.console.log(message);
        }

        if (window.HVPLOGDRAINENABLED) {
            this.queue.push({
                contextId: this.contextId,
                message: this.convertToString(message),
                at: Date.now() / 1000.0
            });
        }
    }

    /**
     * Converts the given object to string for outputting in log.
     */
    convertToString = (thing) => {
        // Already a string, return as-is.
        if (typeof thing === 'string') {
            return thing;
        }

        // If a regular object (i.e. not a html element or something), stringify it.
        if (Object.prototype.toString.call(thing) === '[object Object]' && !Array.isArray(thing)) {
            return JSON.stringify(thing);
        }

        // Default call toString.
        return thing.toString();
    }

    /**
     * Submits the queued logs to the site
     */
    processQueue = async () => {
        if(this.queue.length == 0) {
            return;
        }

        window.console.log("sending logs to site");

        try {
            await this.site.write("mod_hvp_log_drain", { logs: this.queue });
            this.queue = [];
        } catch (ex) {
            window.console.log("error sending logs to site");
        }
    }
}

/**
 * Cached asset manager.
 */
class HvpCachedAssetManager {
    /**
     * Iframe linked to, that the h5p is playing inside of. 
     * @type { HTMLElement }
     */
    iframe;
    
    /**
     * Body element inside iframe that contains the h5p content.
     * @type { HTMLElement }
     */
    body;

    /**
     * Head element inside the iframe that contains the h5p stylesheets
     * @type { HTMLElement }
     */
    head;

    /**
     * A <style> tag that is created to remap fonts to caches sources
     * Is appended to the head element inside the iframe
     * @type { HTMLElement }
     */
    fontRemapStyle;

    /**
     * Cached source mappings. Maps from original source -> cached source.
     * @type { Object }
     */
    mappings = {};

    /**
     * A list of font family names that have been mapped to their cached sources.
     * @type { Array }
     */
    fontsMapped = [];

    /**
     * A list of original file sources (usually /webservice/pluginfile.php) that need
     * to be cached
     * @type { Array }
     */
    fileUrlsToCache = [];

    /**
     * Constructs cache manager
     * @param {HTMLElement} iframe
     * @param {HTMLElement} head
     * @param {HTMLElement} body
     * @param {Array} fileUrlsTocache
     */
    constructor(iframe, head, body, fileUrlsToCache = []) {
        this.iframe = iframe;
        this.head = head;
        this.body = body;
        this.fileUrlsToCache = fileUrlsToCache;
    }
    
    /**
     * Starts the cache replacement process
     * Notes this operates on an interval and will continue until the iframe element goes away
     */
    start = () => {
        window.HVP_LOGGER.log("Starting cache replacement manager interval");

        // Create own style tag for font remappings.
        // This improves performance as finding and replacing in the entire css
        // is quite slow on the device.
        this.fontRemapStyle = document.createElement('style');
        this.head.appendChild(this.fontRemapStyle);

        // Start interval checks and updates.
        setHVPInterval(this.iframe, () => this.onInterval(this), 1000);
    }

    /**
     * Checks run on an interval to update elements, etc...
     */
    onInterval = () => {
        this.checkAndUpdateNewMappings();
        this.checkAndUpdateFontMappings();
        this.updateLoadingNotification();
    }

    /**
     * Returns true if all the fileUrlsToCache are finishing caching and exist in the mapping
     * @return {Boolean}
     */
    isCachingFinished = () => {
        return Object.keys(this.mappings).length == this.filsUrlsToCache;
    }

    /**
     * Returns a list of font names given in php that are not yet mapped
     * @return {Array}
     */
    getUnmappedFontNames = () => {
        const fontSrcMap = window.HVPFONTMAP || {};
        return Object.keys(fontSrcMap).filter(fontName => !this.fontsMapped.includes(fontName));
    }

    /**
     * Checks for unmapped fonts that are cached, and maps them
     */
    checkAndUpdateFontMappings = () => {
        const fontSrcMap = window.HVPFONTMAP || {};
        // Find fonts not yet remapped to a cached src.
        const notMappedFontNames = this.getUnmappedFontNames();

        // Try and replace each one.
        notMappedFontNames.forEach(fontName => {
            const originalSrc = fontSrcMap[fontName];
            const mappedSource = this.mappings[originalSrc];

            // Not mapped yet, ignore.
            if(!mappedSource) {
                window.HVP_LOGGER.log("no remapped source for " + fontName + " available yet");
                return;
            }
            
            // Has a mapped source, replace it.
            const cssToAdd = `
                @font-face {
                    font-family: '${fontName}';
                    src: url('${mappedSource}');
                }
            `;

            // Add css and also mark as mapped so we don't re-add it again later.
            this.fontRemapStyle.textContent += cssToAdd;
            this.fontsMapped.push(fontName);

            window.HVP_LOGGER.log("remapped font " + fontName + " to src " + mappedSource);
        });
    }

    /**
     * Updates the loading notification based on if assets are loading or not
     */
    updateLoadingNotification = () => {
        const isLoadingCachedAssets = this.isCachingFinished();
        const areFontsUnmapped = this.getUnmappedFontNames().length > 0;
        const isLoading = isLoadingCachedAssets || areFontsUnmapped;

        var loadingbar = document.getElementById('h5p-loading-notification');
        loadingbar.style.display = isLoading ? 'block' : 'none';
    }

    /**
     * Checks the elements with the core-external-content directive, and updates the cached source mapping based on their current state.
     */
    checkAndUpdateNewMappings = async () => {
        const siteid = await appCtx.CoreSitesProvider.getCurrentSiteId();

        // Find the urls needing to be mapped that are not yet.
        const urlsNeedingToBeMapped = this.fileUrlsToCache.filter(url => !Object.keys(this.mappings).includes(url)); 

        // Call getSrcByUrl on all of the srcs. 
        // This will queue the file for download, or return it if its is already downloaded.
        const promises = urlsNeedingToBeMapped.map(async url => {
            const result = await appCtx.CoreFilepoolProvider.getSrcByUrl(siteid, url, null, null, 0, false)
            return {
                originalSrc: url,
                cachedSrc: result
            }
        });
        const results = await Promise.all(promises);
        
        // Filter out the ones with tokenpluginfile still in the name
        // this is a placeholder url the app returns if the file is not cached yet.
        // so any with this in their name are not fulled cached and should be ignored.
        const cachedResults = results.filter(r => !r.cachedSrc.includes('tokenpluginfile.php'));

        if (cachedResults.length > 0) {
            window.HVP_LOGGER.log(cachedResults.length + " new assets finished caching: ");
            cachedResults.forEach(result => window.HVP_LOGGER.log("finished caching: " + result.originalSrc, ", cached source: " + result.cachedSrc));
        }

        cachedResults.forEach(result => this.mappings[result.originalSrc] = result.cachedSrc);

        if (cachedResults.length > 0) {
            this.notifyNewMappings();
        }
    }

    /**
     * Notify the class inside of the h5p iframe of new mappings.
     */
    notifyNewMappings = () => {
        this.iframe.contentWindow.postMessage({ 
            "context": "hvp",
            "action": "newmappings",
            "data": this.mappings
        });
    }
}

/**
 * Handles completion in offline app environment.
 */
class HvpCompletionSyncHandler {
    /**
     * SQLite Db table name
     * @type {string}
     */
    DB_TABLE = 'hvp_mobile_offline_finishes';
    
    /**
     * DB column name for id
     * @type {string}
     */
    DB_COLUMN_ID = 'id';

    /**
     * DB column name for data
     * @type {string}
     */
    DB_COLUMN_REQUESTS = 'data';

    /**
     * DB column name for contextId
     * @type {string}
     */
    DB_COLUMN_CONTEXTID = 'contextId';

    /**
     * Iframe that the H5P content is playing in
     * @type {HTMLElement}
     */
    iframe;

    /*
     * Create manager
     * @param {HTMLElement} iframe
     */
    constructor(iframe) {
        this.iframe = iframe;
    }

    /**
     * Sets up and starts processing
     */
    start = async () => {
        const H5P = this.iframe.contentWindow.H5P;

        // We need to hook into the global H5P variable.
        if (!H5P) {
            window.HVP_LOGGER.log("H5P is not defined globally, cannot capture completion");
            return;
        }

        await this.ensureDBSetup();

        // Overwrite the onCompletion callback with our custom cached method.
        H5P.setFinished = async (contentId, score, maxScore, time) => {
            // Store this completion and sync.
            await this.storeForSync({
                contentId,
                score,
                maxScore,
                time
            });

            // Try sync - device might be online.
            await this.sync();
        };

        window.HVP_LOGGER.log("successfully overwrote setFinished to sync completions offline");

        // Register CRON handler (note this is mobile app cron, not Moodle web cron.)
        // Essentially is just a background service to run code.
        var cronhandler = new AddonModHvpSyncCronHandlerService();
        cronhandler.handler = self;
        appCtx.CoreCronDelegate.register(cronhandler);
        window.HVP_LOGGER.log("Successfully registered mobile CRON handler to sync completions")

        // Start an interval that checks if completions are pending, and hides/unhides the notification for the user.
        const completionnotification = document.getElementById('h5p-grade-sync-notification');
        setHVPInterval(this.iframe, async () => {
            const visible = await this.hasRecordsToSync(window.HVPCONTEXTID);
            completionnotification.style.display = !visible ? 'none' : 'block';
        }, 1000);

        // Try to sync on load, there might be old records waiting.
        this.sync();
    }
    
    /**
     * Ensures the custom database table is setup
     */
    ensureDBSetup = async () => {
        var db = appCtx.CoreSitesProvider.getCurrentSite().getDb();
        var exists = (await db.execute(`SELECT name FROM sqlite_schema WHERE type='table' AND name = '${this.DB_TABLE}';`)).rows.length != 0;
        
        // Ignore if already setup.
        if (exists) {
            window.HVP_LOGGER.log("mod_hvp mobile completionsync: DB table setup already");
            return;
        }

        // Not setup - set it up.
        window.HVP_LOGGER.log("mod_hvp mobile completionsync: Setting up DB table");

        var columns = [{
            name: this.DB_COLUMN_ID,
            type: 'TEXT',
            primaryKey: true
        }, {
            name: this.DB_COLUMN_CONTEXTID,
            type: 'INTEGER'
        }, {
            name: this.DB_COLUMN_REQUESTS,
            type: 'TEXT'
        }];
        await db.createTable(this.DB_TABLE, columns, [], [], [], 1);

        window.HVP_LOGGER.log("mod_hvp mobile completionsync: DB setup complete");
    }

    /**
     * Stores the given data in the local database, so it can be synced
     * @param {object} data unstructured data to store
     */
    storeForSync = async (data) => {
        window.HVP_LOGGER.log("mod_hvp mobile completionsync: Storing completion data");
        window.HVP_LOGGER.log(data);

        var db = appCtx.CoreSitesProvider.getCurrentSite().getDb();
        await db.insertRecord(this.DB_TABLE, {
            'id': window.crypto.randomUUID(),
            'contextId': window.HVPCONTEXTID,
            'data': JSON.stringify(data),
        });
    }

    /**
     * Syncs all the data stored in the custom database,
     */
    sync = async () => {
        window.HVP_LOGGER.log("mod_hvp mobile completionsync: Starting sync");
        var site = appCtx.CoreSitesProvider.getCurrentSite();
        var db = await site.getDb();

        const records = await db.getRecords(this.DB_TABLE);

        window.HVP_LOGGER.log("mod_hvp mobile completionsync: Found records:");
        window.HVP_LOGGER.log(records);

        await Promise.all(records.map(r => this.syncRecord(r, this)));

        window.HVP_LOGGER.log("mod_hvp mobile completionsync: Done");
        
        // Update the sync notification (will show to user if sync failed).
        if (window.HVPupdateFinishSyncNotification) {
            window.HVPupdateFinishSyncNotification();
        }
    }

    /**
     * Returns true if there are records waiting to be synced for the given context.
     * Usually used to display a notification to user that completions are pending
     * @return bool
     */
    hasRecordsToSync = async (contextId) => {
        var db = appCtx.CoreSitesProvider.getCurrentSite().getDb();
        var count = await db.countRecords(this.DB_TABLE, { 'contextId': contextId });
        return count > 0;
    }

    /**
     * Syncs the given record,
     * @param {Object} record record stored when hvp emitted its completion event
     * @param {Object} thisContext 
     */
    syncRecord = async (record, thisContext) => {
        var site = appCtx.CoreSitesProvider.getCurrentSite();
        var db = site.getDb();

        window.HVP_LOGGER.log("mod_hvp mobile completionsync: syncing record:")
        window.HVP_LOGGER.log(record);

        try {
            var data = JSON.parse(record.data);
            
            var params = {
                'contextId': record.contextId,
                'score': data.score,
                'maxScore': data.maxScore
            }
            window.HVP_LOGGER.log(params);

            // This essentially just calls a webservice on the linked site.
            const res = await site.write('mod_hvp_submit_mobile_finished', params);

            if (!res.success) {
                throw new Error("Webservice did not respond with success=true");
            }

            // Success - so delete the record from the SQLite db.
            db.deleteRecords(thisContext.DB_TABLE, { 'id': record.id });

            window.HVP_LOGGER.log("mod_hvp mobile completionsync: success for " + record.id);
        } catch (e) {
            window.HVP_LOGGER.log("mod_hvp mobile completionsync: Got exception: ");
            window.HVP_LOGGER.log(e);
        }
    }
}

/**
 * Mobile app cron handler
 * Used to sync completions even when the h5p activity is not open.
 */
class AddonModHvpSyncCronHandlerService {

    /**
     * Handler name
     * @param {string}
     */
    name = 'AddonHVPSyncCronHandler';

    /**
     * Handler
     * @param {HvpCompletionSyncHandler}
     */
    handler

    /**
     * Execute for a given site
     * @param {string} siteid
     * @param {boolean} force
     */
    execute = (siteId, force) => {
        if(this.handler && this.handler.sync) {
            this.handler.sync();
        } else {
            window.console.warn("mod_hvp failed to sync - this.handler or this.handler.sync were undefined. This: ");
            window.console.log(this);
        }
        
        // We don't care if this fails, just keep re-trying.
        return true;
    }

    /**
     * Returns interval
     * @return {Number
     */
    getInterval() {
        // 5 mins interval.
        // Note the minimum interval is 5 minutes (enforced by the app).
        return 300000;
    }
}

