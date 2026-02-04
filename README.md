# Bundled CMDB
Small CMDB project that uses ESET data sent to database for asset management, uses Keycloak as SSO provider for user authentication, S3 for file submission and access related to each asset

<!-- buttons -->

<!-- endbuttons -->

## Requirement:

* [Docker Compose](https://docs.docker.com/engine/install/)
* MySQL/MariaDB
* Keycloak for SSO
* S3-like for object storage
* ESET data to populate

## Deployment instructions:

* Download `/docker` files on your server, example:
```
curl -o .env https://raw.githubusercontent.com/ivancarlosti/bundledcmdb/main/docker/.env
curl -o docker-compose.yml https://raw.githubusercontent.com/ivancarlosti/bundledcmdb/main/docker/docker-compose.yml
```
* Edit both `.env`, `docker-compose.yml` files
* Start Docker Compose, example:
```
docker compose pull && docker compose up -d
```

<!-- footer -->
---

## 🧑‍💻 Consulting and technical support
* For personal support and queries, please submit a new issue to have it addressed.
* For commercial related questions, please [**contact me**][ivancarlos] for consulting costs. 

| 🩷 Project support |
| :---: |
If you found this project helpful, consider [**buying me a coffee**][buymeacoffee]
|Thanks for your support, it is much appreciated!|

[ivancarlos]: https://ivancarlos.me
[buymeacoffee]: https://www.buymeacoffee.com/ivancarlos
