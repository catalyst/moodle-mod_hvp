// Hook into H5P.setSource to pass any elements being set to the replacer.
// This is necessary since it seems some content types such as 360 degree image
// use a canvas, which does not easily expose a way to query for <img> elements.
// Using this we can let the replacer know of any elements that might need caching inside of the h5p.
// regardless of if they are inside a canvas or not.
const originalH5PSetSource = H5P.setSource;
H5P.setSource = (element, src, contentId) => {
    hvpLog('mod_hvp inside iframe: setSource called, adding element to list');
    window.HVP_ASSET_REPLACER.addElementToConsider(element);
    return originalH5PSetSource(element, src, contentId);
}

// Hook into H5P.getPath to return the cache path, instead of the default pluginfile path.
// this is necessary as some content types such as Find the hotspots use this function
// to set an interval src url to an image.
const originalH5PGetPath = H5P.getPath;
H5P.getPath = (src, contentId) => {
    const mapping = window.HVP_ASSET_REPLACER.findMappedSourceByPath(src);
    hvpLog('mod_hvp inside iframe: getPath called for ' + src + " mapped to: " + mapping);

    // We should always have a mapping, since we intentionally wait for all assets
    // to finish caching before the h5p script begins execution. 
    if (mapping != '') {
        return mapping;
    }

    // But in case of catastrophic failure, fall back to default.
    return originalH5PGetPath(src, contentId);
}
hvpLog('mod_hvp inside iframe: overloaded H5P.setSource and H5P.getPath');
