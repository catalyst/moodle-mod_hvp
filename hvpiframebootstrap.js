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
            window.hvp.logger.log("iframe with id: " + iframe.id + " - isConnected changed to false indicating page unload, cancelling interval");
            clearInterval(interval);
            return;
        };

        fn();
    }, delay);
    return interval;
}

/**
 * Adds an event listener for a particular event on the window.
 * The listener is cleared automatically if the iframe becomes unloaded.
 * This is because similar to setHVPInterval, the window is global to the app
 * @param {HTMLElement} iframe
 * @param {string} eventname
 * @param {() => void} callback function when the event is triggered
 */
function setHVPWindowEventListener(iframe, eventname, fn) {
    const controller = new AbortController();

    window.addEventListener(eventname, fn, { signal: controller.signal });

    var interval = setInterval(() => {
        if (!iframe.isConnected) {
            window.hvp.logger.log("iframe with id: " + iframe.id + " - isConnected changed to false indicating page unload, cancelling window event listener for " + eventname);

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

elementReady('#' + window.hvp.selectors.iframe).then(async iframe => {
    var logger = new HvpLogger(iframe, window.hvp.id);
    logger.start();
    window.hvp.logger = logger;

    // Load the hvp core js first OUTSIDE the iframe.
    // This will set up necessary event handlers, etc...
    // Note it does NOT include the content type js.
    eval(window.hvp.corejs);

    // Set a reasonable initial height.
    iframe.style.height = "400px";

    window.hvp.logger.log("setting up iframe - id " + iframe.id);
    var head = iframe.contentWindow.document.head;
    var body = iframe.contentWindow.document.body;

    // Add the element to hook into.
    var hookelement = document.createElement('div');
    hookelement.classList.add('h5p-content');
    hookelement.setAttribute('data-content-id', window.hvp.id); // This var is set by moodle.
    body.appendChild(hookelement);

    // Inject script with the middleware js
    // this must run before the hvp js runs, so it can accept
    // the mappings from the parent window.
    var middlewarescript = document.createElement('script');
    middlewarescript.textContent = window.hvp.middlewarejs;
    head.appendChild(middlewarescript);

    // Inject stylesheet.
    var stylesheet = document.createElement('style');
    stylesheet.textContent = window.hvp.css;
    head.appendChild(stylesheet);

    window.hvp.logger.log("Done injecting CSS. CSS length: " + window.hvp.css?.length);
    window.hvp.logger.log("Style tag injected:");
    window.hvp.logger.log(stylesheet);

    var cachedAssetManager = new HvpCachedAssetManager(iframe, head, body, window.hvp.files || []);
    cachedAssetManager.start();

    var completionManager = new HvpCompletionSyncHandler(iframe);

    // Wait for all the cached assets to cache before loading the h5p.
    // this is very important, as some h5p content types call h5p methods
    // such as h5p.getPath and expect an instant return, instead of async.
    const loadInterval = setHVPInterval(iframe, () => {
        if (!cachedAssetManager.isCachingFinished()) {
            // Not done, ignore.
            window.hvp.logger.log("Assets not cached yet, not injecting hvp js yet");
            return;
        }

        clearInterval(loadInterval);

        // Ensure the child window has received the updated mappings.
        cachedAssetManager.notifyNewMappings();

        // Inject hvp, it will play as if the page just had loaded.
        injectHvpJs(head);

        // Start completion manager only after injecting, otherwise
        // the H5P global object is not accessible.
        completionManager.start();
    }, 250);

    // Put utility classes onto window for easy debugging.
    window.hvp.cached_asset_manager = cachedAssetManager;
    window.hvp.completion_manager = completionManager;
});

/**
 * Inserts the h5p content type js into the element.
 * This is generally done after all the assets have finished caching.
 * @param {HTMLElement} Head element to add script tag to
 */
function injectHvpJs(head) {
    // Inject script which contains all the cached hvp code.
    var script = document.createElement('script');

    // Add small debug log + the entire HVP js to this iframe.
    script.textContent = "window.console.log('mod_hvp mobile: iframe loaded (this log is from inside iframe)');";
    script.textContent += window.hvp.hvpjs;
    script.textContent += window.hvp.overloadjs;
    head.appendChild(script);

    window.hvp.logger.log("Done injecting iframe with h5p contents. JS size: " + window.hvp.hvpjs?.length);
    window.hvp.logger.log("Script tag injected: ");
    window.hvp.logger.log(script);
}

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

        if (window.hvp.logdrainenabled) {
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

        // Make a deep copy of the queue. This reduces the chance of a race condition on the queue variable.
        const tempQueue = JSON.parse(JSON.stringify(this.queue));
        this.queue = [];

        window.console.log("sending logs to site " + tempQueue.length);

        try {
            await this.site.write("mod_hvp_log_drain", { logs: tempQueue });
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
        window.hvp.logger.log("Starting cache replacement manager interval");

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
        return Object.keys(this.mappings).length == this.fileUrlsToCache.length;
    }

    /**
     * Returns a list of font names given in php that are not yet mapped
     * @return {Array}
     */
    getUnmappedFontNames = () => {
        const fontSrcMap = window.hvp.fontmap || {};
        return Object.keys(fontSrcMap).filter(fontName => !this.fontsMapped.includes(fontName));
    }

    /**
     * Checks for unmapped fonts that are cached, and maps them
     */
    checkAndUpdateFontMappings = () => {
        const fontSrcMap = window.hvp.fontmap || {};
        // Find fonts not yet remapped to a cached src.
        const notMappedFontNames = this.getUnmappedFontNames();

        // Try and replace each one.
        notMappedFontNames.forEach(fontName => {
            const originalSrc = fontSrcMap[fontName];
            const mappedSource = this.mappings[originalSrc];

            // Not mapped yet, ignore.
            if(!mappedSource) {
                window.hvp.logger.log("no remapped source for " + fontName + " available yet");
                return;
            }
            
            // Has a mapped source, replace it.
            const cssToAdd = `
                @font-face {
                    font-family: '${fontName}';
                    src: url('${mappedSource}') format("truetype");
                    font-style: normal;
                }
            `;

            // Add css and also mark as mapped so we don't re-add it again later.
            this.fontRemapStyle.textContent += cssToAdd;
            this.fontsMapped.push(fontName);

            window.hvp.logger.log("remapped font " + fontName + " to src " + mappedSource);
        });
    }

    /**
     * Updates the loading notification based on if assets are loading or not
     */
    updateLoadingNotification = () => {
        const isLoadingCachedAssets = !this.isCachingFinished();
        const areFontsUnmapped = this.getUnmappedFontNames().length > 0;
        const isLoading = isLoadingCachedAssets || areFontsUnmapped;

        var loadingbar = document.getElementById(window.hvp.selectors.loadingnotification);
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
            window.hvp.logger.log(cachedResults.length + " new assets finished caching: ");
            cachedResults.forEach(result => window.hvp.logger.log("finished caching: " + result.originalSrc, ", cached source: " + result.cachedSrc));
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
    static DB_TABLE = 'hvp_mobile_offline_finishes';
    
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
            HvpCompletionSyncHandler.log("H5P is not defined globally, cannot capture completion");
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
            await HvpCompletionSyncHandler.sync();
        };

        HvpCompletionSyncHandler.log("successfully overwrote setFinished to sync completions offline");

        // Register CRON handler (note this is mobile app cron, not Moodle web cron.)
        // Essentially is just a background service to run code.
        var cronhandler = new AddonModHvpSyncCronHandlerService();
        cronhandler.handler = HvpCompletionSyncHandler;
        appCtx.CoreCronDelegate.register(cronhandler);
        HvpCompletionSyncHandler.log("Successfully registered mobile CRON handler to sync completions")

        // Start an interval that checks if completions are pending, and hides/unhides the notification for the user.
        const completionnotification = document.getElementById(window.hvp.selectors.gradesyncnotification);
        setHVPInterval(this.iframe, async () => {
            const visible = await this.hasRecordsToSync(window.hvp.contextid);
            completionnotification.style.display = !visible ? 'none' : 'block';
        }, 1000);

        // Try to sync on load, there might be old records waiting.
        HvpCompletionSyncHandler.sync();
    }
    
    /**
     * Ensures the custom database table is setup
     */
    ensureDBSetup = async () => {
        var db = appCtx.CoreSitesProvider.getCurrentSite().getDb();
        var exists = (await db.execute(`SELECT name FROM sqlite_schema WHERE type='table' AND name = '${HvpCompletionSyncHandler.DB_TABLE}';`)).rows.length != 0;
        
        // Ignore if already setup.
        if (exists) {
            HvpCompletionSyncHandler.log("mod_hvp mobile completionsync: DB table setup already");
            return;
        }

        // Not setup - set it up.
        HvpCompletionSyncHandler.log("mod_hvp mobile completionsync: Setting up DB table");

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
        await db.createTable(HvpCompletionSyncHandler.DB_TABLE, columns, [], [], [], 1);

        HvpCompletionSyncHandler.log("mod_hvp mobile completionsync: DB setup complete");
    }

    /**
     * Stores the given data in the local database, so it can be synced
     * @param {object} data unstructured data to store
     */
    storeForSync = async (data) => {
        HvpCompletionSyncHandler.log("mod_hvp mobile completionsync: Storing completion data");
        HvpCompletionSyncHandler.log(data);

        var db = appCtx.CoreSitesProvider.getCurrentSite().getDb();
        await db.insertRecord(HvpCompletionSyncHandler.DB_TABLE, {
            'id': window.crypto.randomUUID(),
            'contextId': window.hvp.contextid,
            'data': JSON.stringify(data),
        });
    }

    /**
     * Syncs all the data stored in the custom database.
     * Note - this MUST be static, so it can be called outside of this page by the CRON handler.
     */
    static sync = async () => {
        HvpCompletionSyncHandler.log("mod_hvp mobile completionsync: Starting sync");
        var site = appCtx.CoreSitesProvider.getCurrentSite();
        var db = await site.getDb();

        const records = await db.getRecords(HvpCompletionSyncHandler.DB_TABLE);

        HvpCompletionSyncHandler.log("mod_hvp mobile completionsync: Found records:");
        HvpCompletionSyncHandler.log(records);

        await Promise.all(records.map(r => HvpCompletionSyncHandler.syncRecord(r, this)));

        HvpCompletionSyncHandler.log("mod_hvp mobile completionsync: Done");
    }

    /**
     * Returns true if there are records waiting to be synced for the given context.
     * Usually used to display a notification to user that completions are pending
     * @return bool
     */
    hasRecordsToSync = async (contextId) => {
        var db = appCtx.CoreSitesProvider.getCurrentSite().getDb();
        var count = await db.countRecords(HvpCompletionSyncHandler.DB_TABLE, { 'contextId': contextId });
        return count > 0;
    }

    /**
     * Syncs the given record.
     * Note - this MUST be static, so it can be called outside of this page by the CRON handler.
     * @param {Object} record record stored when hvp emitted its completion event
     * @param {Object} thisContext 
     */
    static syncRecord = async (record, thisContext) => {
        var site = appCtx.CoreSitesProvider.getCurrentSite();
        var db = site.getDb();

        HvpCompletionSyncHandler.log("mod_hvp mobile completionsync: syncing record:")
        HvpCompletionSyncHandler.log(record);

        try {
            var data = JSON.parse(record.data);
            
            var params = {
                'contextId': record.contextId,
                'score': data.score,
                'maxScore': data.maxScore
            }
            HvpCompletionSyncHandler.log(params);

            // This essentially just calls a webservice on the linked site.
            const res = await site.write('mod_hvp_submit_mobile_finished', params);

            if (!res.success) {
                throw new Error("Webservice did not respond with success=true");
            }

            // Success - so delete the record from the SQLite db.
            db.deleteRecords(thisContext.DB_TABLE, { 'id': record.id });

            HvpCompletionSyncHandler.log("mod_hvp mobile completionsync: success for " + record.id);
        } catch (e) {
            HvpCompletionSyncHandler.log("mod_hvp mobile completionsync: Got exception: ");
            HvpCompletionSyncHandler.log(e);
        }
    }

    /**
     * Logs a message to HVP logger if available, otherwise the default console.
     * @param {any} msg
     */
    static log = (msg) => {
        if(window.hvp && window.hvp.logger) {
            window.hvp.logger.log(msg);
        } else {
            window.console.log(msg);
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
    name = 'AddonModHVPSyncCronHandler';

    /**
     * Execute for a given site
     * @param {string} siteid
     * @param {boolean} force
     */
    execute = (siteId, force) => {
        HvpCompletionSyncHandler.sync();
        
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

