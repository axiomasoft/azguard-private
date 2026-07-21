# C4 Container Diagram

```mermaid
C4Container
    title Containers
    Container(web, "Web App")
    ContainerDb(db, "Database")
    Rel(web, db, "Reads/Writes")
```
