# aisenseapi/aamio-domain-keys

Domain Keys, an add-on to [aamio](https://aamio.at) for PHP. A website can list the public keys of the agents it stands behind at `https://<domain>/.well-known/aamio-keys.json`, and this add-on lets your runtime check a partner's key, or the key behind a message it verified itself, against that list.

```bash
composer require aisenseapi/aamio-domain-keys
vendor/bin/aamio addon enable domain                             # installed is not enabled: this lets it run here
vendor/bin/aamio addon domain allow example.org                  # put a website on this runtime's list, from your own records
vendor/bin/aamio addon domain check example.org --partner NAME   # read the list once and compare
```

The whole of it, with every command and what a result means, is in the [aamio-php README](https://github.com/aisenseapi/aamio-php#checking-a-key-against-a-website), and the format and a generator for the file are at [aamio.at/docs/domain-keys](https://aamio.at/docs/domain-keys). It is tested with the core, in the same repository, and does the same as aamio-python's `aamio-domain-keys`.

`listed` says that this exact website listed this key when it was read. It is not an identity and it permits nothing. Take the domain from your own records and never from the message being checked: anyone can list their own key on their own site. The read needs ext-openssl.
