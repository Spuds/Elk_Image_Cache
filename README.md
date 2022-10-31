## ElkArte Image Cache

## License
This Elkarte addon is subject to the terms of the Mozilla Public License version 1.1 (the "License"). You can obtain a copy of the License at [http://mozilla.org/MPL/1.1/.](http://mozilla.org/MPL/1.1/)

## Introduction
This will serve images embedded with [IMG] tags from your domain through a proxy mechanism. The remote image is saved to your cache directory and served from there. You can choose to do this for all [IMG] tags or just those that would cause "insecure content" warnings when your site is running HTTPS and the image is from an external site using HTTP

## Features
  - Added as a core feature
  - Scheduled task to remove images which have not been accessed in a given period of time
  - Option to cache all [img][/img] files or just those required for proper https validation
  - Automatically will retry fetching failed images up to 10 times.
  - No source edits.
  - Cached images are compressed versions of the source image and have a link back to the original.
